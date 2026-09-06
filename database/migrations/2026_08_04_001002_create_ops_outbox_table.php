<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Transactional outbox (NFR-3.2, NFR-3.3).
     *
     * Every side effect — email, AI dispatch, webhook — is written here inside
     * the same transaction as the state change that caused it, then relayed by
     * a worker. Without this you get the classic split-brain: the contract
     * commits as Approved, the mail server hiccups, and the lawyer is never
     * told. With ~20 notification-triggering events in the MVP that failure is
     * not hypothetical.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table ops.outbox (
                id             bigint generated always as identity primary key,
                tenant_id      uuid not null,
                aggregate_type varchar(64) not null,
                aggregate_id   uuid not null,
                event_type     varchar(96) not null,
                payload        jsonb not null,
                available_at   timestamptz not null default now(),
                published_at   timestamptz,
                attempts       smallint not null default 0,
                last_error     text,
                created_at     timestamptz not null default now()
            );

            -- bigint identity, not uuid: the relay reads in insertion order and
            -- this table is high-churn append/delete. Ordering is the whole
            -- point here.
            create index outbox_unpublished_idx
                on ops.outbox (available_at, id)
                where published_at is null;
            create index outbox_aggregate_idx on ops.outbox (aggregate_type, aggregate_id);

            comment on table ops.outbox is
              'Relay claims rows with SELECT ... FOR UPDATE SKIP LOCKED ordered by (available_at, id). Prune published rows on a retention schedule.';

            -- Signed webhook deliveries from the AI service (and, later, gateways).
            create table ops.webhook_deliveries (
                id              uuid primary key default app.uuid_generate_v7(),
                direction       varchar(8) not null,      -- 'inbound' | 'outbound'
                source          varchar(48) not null,     -- 'ai_service' | 'payment_gateway'
                event_type      varchar(96),
                signature_valid boolean,
                -- Replay defence: a signature is accepted once. The unique
                -- index below is what turns "we check the HMAC" into "a
                -- captured request cannot be resent".
                signature       varchar(255),
                request_id      uuid,
                http_status     smallint,
                payload         jsonb,
                received_at     timestamptz not null default now(),

                constraint webhook_direction_valid check (direction in ('inbound', 'outbound'))
            );

            create unique index webhook_signature_key
                on ops.webhook_deliveries (source, signature)
                where direction = 'inbound' and signature is not null;
            create index webhook_received_idx on ops.webhook_deliveries (received_at desc);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists ops.webhook_deliveries;
            drop table if exists ops.outbox;
        SQL);
    }
};
