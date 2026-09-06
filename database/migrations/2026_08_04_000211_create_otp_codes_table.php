<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Module 1 (FR-1.x), NFR-4.5. Passwordless login/registration: a 6-digit
     * code sent to the user's email (WhatsApp channel reserved for later) is
     * the only credential. This replaces UC-038's password check entirely.
     *
     * Modeled separately from app.one_time_tokens: that table holds long
     * random tokens meant for a clicked link, this holds a short numeric
     * code a human types in, so it needs an attempt counter for brute-force
     * protection that a 256-bit link token never does.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.otp_channel as enum ('email', 'whatsapp');

            create table app.otp_codes (
                id           uuid primary key default app.uuid_generate_v7(),
                user_id      uuid           not null references app.users (id) on delete cascade,
                channel      app.otp_channel not null,
                -- Snapshot of the address/number the code was actually sent
                -- to, independent of app.users so a later email/phone change
                -- can't retroactively alter what an old, already-consumed row
                -- claims was verified.
                destination  text           not null,
                code_hash    app.sha256_hex not null,
                expires_at   timestamptz    not null,
                consumed_at  timestamptz,
                attempts     smallint       not null default 0,
                max_attempts smallint       not null default 5,
                request_ip   inet,
                created_at   timestamptz    not null default now(),

                constraint otp_codes_attempts_within_max check (attempts <= max_attempts)
            );

            -- At most one live code per user per channel: requesting a new
            -- one must consume/expire the previous row first.
            create unique index otp_codes_live_key
                on app.otp_codes (user_id, channel)
                where consumed_at is null;

            create index otp_codes_expiry_idx on app.otp_codes (expires_at) where consumed_at is null;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.otp_codes;
            drop type if exists app.otp_channel;
        SQL);
    }
};
