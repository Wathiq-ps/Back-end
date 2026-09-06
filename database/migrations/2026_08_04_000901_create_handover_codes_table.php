<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * FR-7.13, UC-052.
     *
     * The QR encodes a token; the database stores only its hash, so a leaked
     * backup cannot be used to confirm a handover. Single use, time-boxed, and
     * bound to the contract.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.handover_code_status as enum ('active', 'consumed', 'expired', 'revoked');

            create table app.handover_codes (
                id           uuid primary key default app.uuid_generate_v7(),
                tenant_id    uuid not null references app.tenants (id) on delete restrict,
                contract_id  uuid not null,
                token_hash   app.sha256_hex not null,
                status       app.handover_code_status not null default 'active',
                issued_by    uuid not null references app.users (id) on delete restrict,
                issued_at    timestamptz not null default now(),
                expires_at   timestamptz not null,
                consumed_at  timestamptz,
                consumed_by  uuid references app.users (id) on delete restrict,
                consumed_ip  inet,

                constraint handover_expiry_after_issue check (expires_at > issued_at),
                constraint handover_consumed_complete check (
                    (status = 'consumed') = (consumed_at is not null and consumed_by is not null)
                ),

                foreign key (contract_id, tenant_id)
                    references app.contracts (id, tenant_id) on delete cascade
            );

            create unique index handover_codes_token_key on app.handover_codes (token_hash);
            -- One live code per contract; reissuing revokes the previous one.
            create unique index handover_codes_one_active on app.handover_codes (contract_id) where status = 'active';
            create index handover_codes_expiry_idx on app.handover_codes (expires_at) where status = 'active';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.handover_codes;
            drop type if exists app.handover_code_status;
        SQL);
    }
};
