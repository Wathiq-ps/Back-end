<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Mandatory on every mutating financial endpoint. Stores the request hash
     * and the response, so a retry returns the original result instead of
     * performing the operation twice.
     *
     * The request_hash comparison matters: the same key with a different body
     * is a client bug and must be rejected loudly (409), not served the cached
     * response.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table ops.idempotency_keys (
                id              uuid primary key default app.uuid_generate_v7(),
                tenant_id       uuid not null,
                user_id         uuid,
                idempotency_key varchar(128) not null,
                endpoint        varchar(191) not null,
                request_hash    app.sha256_hex not null,
                locked_at       timestamptz,
                response_status smallint,
                response_body   jsonb,
                completed_at    timestamptz,
                expires_at      timestamptz not null default now() + interval '24 hours',
                created_at      timestamptz not null default now()
            );

            create unique index idempotency_keys_key on ops.idempotency_keys (tenant_id, endpoint, idempotency_key);
            create index idempotency_keys_expiry_idx on ops.idempotency_keys (expires_at);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop table if exists ops.idempotency_keys;');
    }
};
