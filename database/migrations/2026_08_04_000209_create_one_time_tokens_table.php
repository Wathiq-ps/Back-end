<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Email verification (UC-039) and password reset (UC-041). Replaces
     * Laravel's default `password_reset_tokens` table, which was removed from
     * the framework migration.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.one_time_token_purpose as enum ('email_verification', 'password_reset', 'email_change');

            create table app.one_time_tokens (
                id          uuid primary key default app.uuid_generate_v7(),
                user_id     uuid        not null references app.users (id) on delete cascade,
                purpose     app.one_time_token_purpose not null,
                token_hash  app.sha256_hex not null,
                payload     jsonb,          -- e.g. the requested new address for email_change
                expires_at  timestamptz not null,
                consumed_at timestamptz,
                created_at  timestamptz not null default now(),
                request_ip  inet
            );

            create unique index one_time_tokens_hash_key on app.one_time_tokens (token_hash);
            -- At most one live token per purpose per user: re-requesting
            -- invalidates the old one.
            create unique index one_time_tokens_live_key
                on app.one_time_tokens (user_id, purpose)
                where consumed_at is null;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.one_time_tokens;
            drop type if exists app.one_time_token_purpose;
        SQL);
    }
};
