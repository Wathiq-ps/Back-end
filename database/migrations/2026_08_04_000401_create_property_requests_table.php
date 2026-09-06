<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Module 5 (FR-5.1 .. FR-5.6), UC-020, UC-021, UC-006, UC-007, UC-043, UC-063.
     *
     * OPEN ITEM — FR-5.7 does not exist. UC-063 (Close Request) has no
     * functional requirement behind it: nothing in the SRS defines the
     * conditions under which a request closes or what read-only means
     * afterwards. This migration assumes closure is terminal, reachable from
     * `accepted` once its contract completes and from `pending` on property
     * withdrawal. Confirm before M5 ships.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.request_status as enum (
                'pending',
                'accepted',
                'rejected',
                'cancelled',   -- withdrawn by the requester (FR-5.5)
                'closed',      -- terminal, post-contract (UC-063)
                'expired'
            );

            create table app.property_requests (
                id               uuid primary key default app.uuid_generate_v7(),
                tenant_id        uuid not null references app.tenants (id) on delete restrict,
                reference        varchar(24) not null,
                property_id      uuid not null,
                requester_id     uuid not null references app.users (id) on delete restrict,
                type             app.listing_type not null,   -- purchase == 'sale' listing

                offered_amount   app.money_minor,
                offered_currency app.currency_code references app.currencies (code),
                -- Rental term. Null for purchase requests.
                term_start       date,
                term_end         date,
                message          text,

                status           app.request_status not null default 'pending',
                responded_by     uuid references app.users (id),
                responded_at     timestamptz,
                response_note    text,
                expires_at       timestamptz,
                closed_at        timestamptz,
                created_at       timestamptz not null default now(),
                updated_at       timestamptz not null default now(),

                constraint requests_offer_pair check (
                    (offered_amount is null) = (offered_currency is null)
                ),
                constraint requests_term_pair check (
                    (term_start is null) = (term_end is null)
                ),
                constraint requests_term_ordered check (
                    term_end is null or term_end > term_start
                ),
                constraint requests_rent_has_term check (
                    type <> 'rent' or term_start is not null
                ),
                constraint requests_response_complete check (
                    status not in ('accepted', 'rejected') or (responded_by is not null and responded_at is not null)
                ),

                unique (id, tenant_id),
                foreign key (property_id, tenant_id)
                    references app.properties (id, tenant_id) on delete restrict
            );

            create unique index requests_reference_key on app.property_requests (tenant_id, reference);

            -- BR: one live request per (property, requester). Re-applying while
            -- one is pending is a duplicate, not a second offer.
            create unique index requests_one_pending_per_requester
                on app.property_requests (property_id, requester_id)
                where status = 'pending';

            -- BR: a property can have at most one accepted request at a time.
            -- Without this two buyers can both be accepted and two contracts
            -- open on one property — a race that a check-then-insert in
            -- application code will lose under load.
            create unique index requests_one_accepted_per_property
                on app.property_requests (property_id)
                where status = 'accepted';

            create index requests_property_idx  on app.property_requests (property_id, status);
            create index requests_requester_idx on app.property_requests (tenant_id, requester_id, created_at desc);
            create index requests_inbox_idx     on app.property_requests (tenant_id, status, created_at desc);
            create index requests_expiry_idx    on app.property_requests (expires_at) where status = 'pending';

            create trigger requests_touch
                before update on app.property_requests
                for each row execute function app.touch_updated_at();

            -- The requester must not be the owner. Enforced by trigger because
            -- the owner lives on another table.
            create or replace function app.assert_requester_is_not_owner()
            returns trigger
            language plpgsql
            as $$
            begin
                if exists (
                    select 1 from app.properties p
                     where p.id = new.property_id and p.owner_id = new.requester_id
                ) then
                    raise exception 'a property owner cannot submit a request against their own listing'
                        using errcode = 'restrict_violation';
                end if;
                return new;
            end;
            $$;

            create trigger requests_requester_not_owner
                before insert on app.property_requests
                for each row execute function app.assert_requester_is_not_owner();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.property_requests;
            drop function if exists app.assert_requester_is_not_owner();
            drop type if exists app.request_status;
        SQL);
    }
};
