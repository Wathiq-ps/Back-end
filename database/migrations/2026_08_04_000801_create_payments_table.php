<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Module 7 financial portions (FR-7.5, FR-7.6), SRS 3.8.2, UC-022, UC-051.
     *
     * Scope note: commission splitting (FR-7.10/7.11), payouts (FR-7.12) and
     * refunds (FR-7.17) stay out of the MVP; SRS 7.5 says operations settles
     * those manually during Phase 1. The `refunded` statuses exist only so
     * history stays representable when Phase 2 arrives.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- SRS 3.8.2
            create type app.payment_status as enum (
                'pending',
                'processing',
                'succeeded',
                'failed',
                'cancelled',
                'refunded',
                'partially_refunded'
            );

            create type app.payment_method as enum ('wallet', 'card', 'bank_transfer', 'cash_deposit');

            create table app.payments (
                id                 uuid primary key default app.uuid_generate_v7(),
                tenant_id          uuid not null references app.tenants (id) on delete restrict,
                reference          varchar(24) not null,
                contract_id        uuid not null,
                payer_id           uuid not null references app.users (id) on delete restrict,

                amount             app.money_minor   not null,
                currency           app.currency_code not null references app.currencies (code),
                method             app.payment_method not null,
                status             app.payment_status not null default 'pending',

                gateway            varchar(48),
                gateway_reference  varchar(128),
                gateway_payload    jsonb,

                -- UC-022 and UC-051 run against an external gateway over mobile
                -- networks. A retried POST must never charge twice; this is the
                -- anchor for that.
                idempotency_key    varchar(128) not null,

                initiated_at       timestamptz not null default now(),
                confirmed_at       timestamptz,
                failed_at          timestamptz,
                failure_code       varchar(64),
                failure_message    text,

                constraint payments_amount_positive check (amount > 0),
                constraint payments_succeeded_has_confirmation check (
                    status <> 'succeeded' or confirmed_at is not null
                ),
                constraint payments_failed_has_code check (
                    status <> 'failed' or failure_code is not null
                ),
                -- An external payment without a gateway reference cannot be
                -- reconciled.
                constraint payments_external_has_gateway_ref check (
                    method = 'wallet' or status <> 'succeeded' or gateway_reference is not null
                ),

                unique (id, tenant_id),
                foreign key (contract_id, tenant_id)
                    references app.contracts (id, tenant_id) on delete restrict
            );

            create unique index payments_reference_key   on app.payments (tenant_id, reference);
            create unique index payments_idempotency_key on app.payments (tenant_id, idempotency_key);
            create unique index payments_gateway_ref_key on app.payments (gateway, gateway_reference)
                where gateway_reference is not null;
            -- At most one payment in flight or settled per contract in the MVP
            -- (instalments are out of scope).
            create unique index payments_one_live_per_contract
                on app.payments (contract_id)
                where status in ('pending', 'processing', 'succeeded');

            create index payments_contract_idx on app.payments (contract_id, initiated_at desc);
            create index payments_payer_idx    on app.payments (tenant_id, payer_id, initiated_at desc);
            create index payments_pending_idx  on app.payments (status, initiated_at)
                where status in ('pending', 'processing');

            -- Gateway callbacks, kept verbatim. When a reconciliation dispute
            -- happens the question is always "what exactly did the gateway send
            -- us, and when".
            create table app.payment_events (
                id            uuid primary key default app.uuid_generate_v7(),
                tenant_id     uuid not null references app.tenants (id) on delete restrict,
                payment_id    uuid not null,
                from_status   app.payment_status,
                to_status     app.payment_status not null,
                source        varchar(32) not null,   -- 'gateway' | 'system' | 'admin'
                raw_payload   jsonb,
                signature_ok  boolean,
                occurred_at   timestamptz not null default now(),

                foreign key (payment_id, tenant_id)
                    references app.payments (id, tenant_id) on delete cascade
            );

            create index payment_events_payment_idx on app.payment_events (payment_id, occurred_at);

            create trigger payment_events_immutable
                before update or delete on app.payment_events
                for each row execute function app.reject_any_change();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.payment_events;
            drop table if exists app.payments;
            drop type if exists app.payment_method;
            drop type if exists app.payment_status;
        SQL);
    }
};
