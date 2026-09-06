<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * NFR-4.6.
     *
     * "Un-modifiable and un-deletable by ordinary users" is not achievable
     * with a convention or an Eloquent observer. It is achieved with two
     * mechanisms, and both are needed:
     *
     *   1. Grants — wathiq_app holds INSERT and SELECT here and is never
     *      granted UPDATE or DELETE (see the 990000_* migration). This is the
     *      real protection: a compromised application literally lacks the
     *      privilege.
     *   2. The triggers below — catch mistakes made by roles that DO hold the
     *      privilege, such as the migration role during a botched fix.
     *
     * Note honestly what this does not stop: the table owner can disable a
     * trigger, and a superuser can do anything. Off-site shipping of audit
     * records (NFR-10.x) is what covers that, and belongs in the observability
     * workstream.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table audit.audit_logs (
                id            bigint generated always as identity primary key,
                tenant_id     uuid,
                actor_id      uuid,
                actor_role    varchar(32),
                action        varchar(96) not null,
                subject_type  varchar(96) not null,
                subject_id    uuid,
                before_state  jsonb,
                after_state   jsonb,
                ip_address    inet,
                user_agent    text,
                request_id    uuid,
                occurred_at   timestamptz not null default now()
            );

            -- No global tenant scope here on purpose: a platform administrator
            -- must be able to audit across tenants, which is the one
            -- legitimate cross-tenant read.
            create index audit_logs_tenant_idx  on audit.audit_logs (tenant_id, occurred_at desc);
            create index audit_logs_subject_idx on audit.audit_logs (subject_type, subject_id, occurred_at desc);
            create index audit_logs_actor_idx   on audit.audit_logs (actor_id, occurred_at desc);
            create index audit_logs_action_idx  on audit.audit_logs (action, occurred_at desc);

            create or replace function audit.reject_mutation()
            returns trigger
            language plpgsql
            as $$
            begin
                raise exception 'audit.audit_logs is append-only (NFR-4.6): % is not permitted', tg_op
                    using errcode = 'restrict_violation';
            end;
            $$;

            create trigger audit_logs_no_update
                before update on audit.audit_logs
                for each row execute function audit.reject_mutation();

            create trigger audit_logs_no_delete
                before delete on audit.audit_logs
                for each row execute function audit.reject_mutation();

            create trigger audit_logs_no_truncate
                before truncate on audit.audit_logs
                execute function audit.reject_mutation();

            comment on table audit.audit_logs is
              'Append-only. Protected by role grants (primary) and triggers (secondary). Partition by month once volume warrants it — attach a new partition rather than deleting rows.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists audit.audit_logs;
            drop function if exists audit.reject_mutation();
        SQL);
    }
};
