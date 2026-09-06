<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * FR-6.14.
     *
     * Distinct from the audit log: this is queryable domain history shown in
     * the contract timeline UI. The audit log is the compliance record.
     *
     * The actor is read from a transaction-scoped GUC, which the application
     * must set per request:
     *   SET LOCAL wathiq.actor_id = '<user-uuid>';
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table app.contract_status_history (
                id           uuid primary key default app.uuid_generate_v7(),
                tenant_id    uuid not null references app.tenants (id) on delete restrict,
                contract_id  uuid not null,
                from_status  app.contract_status,
                to_status    app.contract_status not null,
                actor_id     uuid references app.users (id) on delete restrict,
                reason       text,
                occurred_at  timestamptz not null default now(),

                foreign key (contract_id, tenant_id)
                    references app.contracts (id, tenant_id) on delete cascade
            );

            create index contract_status_history_idx on app.contract_status_history (contract_id, occurred_at);

            create trigger contract_status_history_immutable
                before update or delete on app.contract_status_history
                for each row execute function app.reject_any_change();

            create or replace function app.record_contract_transition()
            returns trigger
            language plpgsql
            as $$
            begin
                if new.status is distinct from old.status then
                    insert into app.contract_status_history
                        (tenant_id, contract_id, from_status, to_status, actor_id, reason)
                    values
                        (new.tenant_id, new.id, old.status, new.status,
                         nullif(current_setting('wathiq.actor_id', true), '')::uuid,
                         new.cancellation_reason);
                end if;
                return null;
            end;
            $$;

            create trigger contracts_record_transition
                after update of status on app.contracts
                for each row execute function app.record_contract_transition();

            comment on function app.record_contract_transition() is
              'Reads the actor from the wathiq.actor_id GUC. The application sets it per transaction: SET LOCAL wathiq.actor_id = ''<uuid>''.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists contracts_record_transition on app.contracts;
            drop function if exists app.record_contract_transition();
            drop table if exists app.contract_status_history;
        SQL);
    }
};
