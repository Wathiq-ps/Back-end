<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * NFR-4.1, UC-045, UC-084, UC-085.
     *
     * Access tokens are stateless JWTs and are not stored. Refresh tokens are,
     * so that "terminate other sessions" is possible at all — and so a
     * replayed token is detectable.
     *
     * Rotation chain: each refresh mints a successor and sets replaced_by_id
     * on the predecessor. Presenting an already-replaced token means the token
     * leaked; the correct response is to revoke the whole chain, which
     * session_family_id makes a single indexed UPDATE.
     *
     * Unrelated to Laravel's own `sessions` table in the public schema, which
     * is the cookie/HTTP session store for the web admin panel.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.session_revocation_reason as enum (
                'logout', 'rotated', 'user_terminated', 'admin_terminated', 'reuse_detected', 'expired'
            );

            create table app.user_sessions (
                id                 uuid primary key default app.uuid_generate_v7(),
                user_id            uuid        not null references app.users (id) on delete cascade,
                session_family_id  uuid        not null,
                -- SHA-256 of the token. The token itself never touches the
                -- database, so a dump of this table cannot be replayed
                -- against the API.
                token_hash         app.sha256_hex not null,
                replaced_by_id     uuid        references app.user_sessions (id) on delete set null,
                device_name        varchar(191),
                ip_address         inet,
                user_agent         text,
                issued_at          timestamptz not null default now(),
                last_used_at       timestamptz,
                expires_at         timestamptz not null,
                revoked_at         timestamptz,
                revocation_reason  app.session_revocation_reason,

                constraint sessions_expiry_after_issue check (expires_at > issued_at),
                constraint sessions_revoked_has_reason check ((revoked_at is null) = (revocation_reason is null))
            );

            create unique index sessions_token_hash_key on app.user_sessions (token_hash);
            create index sessions_active_idx on app.user_sessions (user_id) where revoked_at is null;
            create index sessions_family_idx on app.user_sessions (session_family_id) where revoked_at is null;
            create index sessions_expiry_idx on app.user_sessions (expires_at) where revoked_at is null;

            create trigger sessions_immutable
                before update on app.user_sessions
                for each row execute function app.reject_column_change('user_id', 'token_hash', 'issued_at', 'session_family_id');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.user_sessions;
            drop type if exists app.session_revocation_reason;
        SQL);
    }
};
