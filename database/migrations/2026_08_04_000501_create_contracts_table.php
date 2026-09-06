<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Module 6 (FR-6.x), SRS 3.7.1 / 3.7.2.
     *
     * This is the table the product exists to protect. Note that the state
     * machine itself is a separate migration (000505_*) — this file only
     * defines the entity and its structural constraints.
     *
     * current_version_id and template_id get their foreign keys added later,
     * once app.contract_versions and app.contract_templates exist.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.contract_type as enum ('sale', 'rent');

            -- SRS 3.7.2. Note: 3.7.2 names 'Requires Modification' while
            -- UC-070's scenario writes 'Modification'. The status table is
            -- authoritative; standardised here.
            create type app.contract_status as enum (
                'draft',
                'under_ai_review',
                'pending_lawyer_review',
                'requires_modification',
                'approved',
                'awaiting_signatures',
                'fully_signed',
                'awaiting_payment',
                'active',
                'completed',
                'cancelled',
                'expired'
            );

            create table app.contracts (
                id                 uuid primary key default app.uuid_generate_v7(),
                tenant_id          uuid not null references app.tenants (id) on delete restrict,
                reference          varchar(24) not null,

                request_id         uuid not null,
                property_id        uuid not null,
                owner_id           uuid not null references app.users (id) on delete restrict,
                beneficiary_id     uuid not null references app.users (id) on delete restrict,
                lawyer_id          uuid references app.users (id) on delete restrict,
                template_id        uuid,

                type               app.contract_type   not null,
                status             app.contract_status not null default 'draft',
                jurisdiction_id    uuid not null references app.jurisdictions (id) on delete restrict,

                value_amount       app.money_minor   not null,
                value_currency     app.currency_code not null references app.currencies (code),

                -- Rental term; null for sale.
                starts_on          date,
                ends_on            date,

                current_version_id uuid,   -- FK added in 000502_*
                created_by         uuid not null references app.users (id) on delete restrict,
                approved_at        timestamptz,
                activated_at       timestamptz,
                completed_at       timestamptz,
                cancelled_at       timestamptz,
                cancellation_reason text,
                created_at         timestamptz not null default now(),
                updated_at         timestamptz not null default now(),

                constraint contracts_parties_distinct check (owner_id <> beneficiary_id),
                constraint contracts_term_pair    check ((starts_on is null) = (ends_on is null)),
                constraint contracts_term_ordered check (ends_on is null or ends_on > starts_on),
                constraint contracts_rent_has_term check (type <> 'rent' or starts_on is not null),
                constraint contracts_cancelled_has_reason check (
                    status <> 'cancelled' or cancellation_reason is not null
                ),
                -- BR-29: no contract may be approved without a licensed lawyer attached.
                constraint contracts_approved_has_lawyer check (
                    status not in ('approved', 'awaiting_signatures', 'fully_signed',
                                   'awaiting_payment', 'active', 'completed')
                    or lawyer_id is not null
                ),

                unique (id, tenant_id),
                foreign key (request_id, tenant_id)
                    references app.property_requests (id, tenant_id) on delete restrict,
                foreign key (property_id, tenant_id)
                    references app.properties (id, tenant_id) on delete restrict
            );

            create unique index contracts_reference_key on app.contracts (tenant_id, reference);
            -- One contract per accepted request.
            create unique index contracts_request_key on app.contracts (request_id);

            create index contracts_property_idx    on app.contracts (property_id, status);
            create index contracts_owner_idx       on app.contracts (tenant_id, owner_id, created_at desc);
            create index contracts_beneficiary_idx on app.contracts (tenant_id, beneficiary_id, created_at desc);
            -- The lawyer's review queue (UC-026 .. UC-029).
            create index contracts_lawyer_queue_idx
                on app.contracts (tenant_id, lawyer_id, status)
                where status in ('pending_lawyer_review', 'requires_modification');
            create index contracts_expiry_idx on app.contracts (ends_on) where status = 'active';

            create trigger contracts_touch
                before update on app.contracts
                for each row execute function app.touch_updated_at();

            -- A property cannot carry two overlapping live rentals. btree_gist
            -- supplies the `=` operator for uuid inside the GiST index; the
            -- daterange handles overlap.
            alter table app.contracts
                add constraint contracts_no_overlapping_rentals
                exclude using gist (
                    property_id with =,
                    daterange(starts_on, ends_on, '[]') with &&
                )
                where (type = 'rent' and status in ('active', 'awaiting_payment', 'fully_signed'));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.contracts;
            drop type if exists app.contract_status;
            drop type if exists app.contract_type;
        SQL);
    }
};
