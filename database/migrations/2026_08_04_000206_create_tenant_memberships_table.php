<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Role assignment is tenant-scoped: the same person can be a lawyer for
     * one company and an owner in another. This table — not app.users — is
     * what carries the tenant for a person.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.membership_status as enum ('active', 'suspended', 'revoked');

            create table app.tenant_memberships (
                id          uuid primary key default app.uuid_generate_v7(),
                tenant_id   uuid not null references app.tenants (id) on delete restrict,
                user_id     uuid not null references app.users (id)   on delete cascade,
                role_id     uuid not null references app.roles (id)   on delete restrict,
                status      app.membership_status not null default 'active',
                granted_by  uuid references app.users (id),
                granted_at  timestamptz not null default now(),
                revoked_at  timestamptz,

                constraint memberships_revoked_has_timestamp check (
                    (status = 'revoked') = (revoked_at is not null)
                )
            );

            create unique index memberships_unique_active
                on app.tenant_memberships (tenant_id, user_id, role_id)
                where status <> 'revoked';

            create index memberships_user_idx   on app.tenant_memberships (user_id)   where status = 'active';
            create index memberships_tenant_idx on app.tenant_memberships (tenant_id) where status = 'active';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.tenant_memberships;
            drop type if exists app.membership_status;
        SQL);
    }
};
