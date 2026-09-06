<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Module 3 (FR-3.x), Module 4 (FR-4.x), NFR-1.2 (<=2s over 100,000 properties).
     *
     * Tenant-integrity pattern used from here on:
     *   parent  ... unique (id, tenant_id)
     *   child   ... foreign key (parent_id, tenant_id) references parent (id, tenant_id)
     * The composite reference makes a cross-tenant row pair structurally
     * impossible. An application bug can then produce a foreign-key violation,
     * but never a photograph of one company's property attached to another's
     * listing.
     *
     * The search_document tsvector is maintained by a trigger in the
     * 000302_* migration (kept separate so search tuning can be revised
     * without touching the table definition).
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.property_type as enum (
                'apartment', 'house', 'villa', 'land', 'office', 'shop', 'warehouse', 'building', 'farm'
            );

            create type app.listing_type as enum ('sale', 'rent');

            -- FR-3.5. `draft` and `pending_verification` are pre-publication;
            -- `under_contract` is entered when a request is accepted and
            -- released on contract cancellation.
            create type app.property_status as enum (
                'draft',
                'pending_verification',
                'published',
                'paused',
                'under_contract',
                'sold',
                'rented',
                'archived',
                'rejected'
            );

            create table app.properties (
                id                 uuid primary key default app.uuid_generate_v7(),
                tenant_id          uuid not null references app.tenants (id) on delete restrict,
                reference          varchar(24) not null,
                owner_id           uuid not null references app.users (id) on delete restrict,
                -- Phase 2 delegation (FR-3.6/3.7). Column present now; no MVP write path.
                broker_id          uuid references app.users (id) on delete set null,

                title              varchar(191) not null,
                description        text,
                type               app.property_type not null,
                listing_type       app.listing_type  not null,
                status             app.property_status not null default 'draft',

                price_amount       app.money_minor   not null,
                price_currency     app.currency_code not null references app.currencies (code),

                area_sqm           numeric(10, 2) not null,
                rooms              smallint,
                bathrooms          smallint,
                floor_number       smallint,
                total_floors       smallint,
                year_built         smallint,
                is_furnished       boolean not null default false,

                location_id        uuid not null references app.locations (id) on delete restrict,
                address_line       varchar(255),
                coordinates        geography(Point, 4326),

                search_document    tsvector,

                published_at       timestamptz,
                archived_at        timestamptz,
                created_at         timestamptz not null default now(),
                updated_at         timestamptz not null default now(),
                deleted_at         timestamptz,

                constraint properties_area_positive   check (area_sqm > 0),
                constraint properties_rooms_sane      check (rooms      is null or rooms      between 0 and 100),
                constraint properties_bathrooms_sane  check (bathrooms  is null or bathrooms  between 0 and 100),
                constraint properties_year_sane       check (year_built is null or year_built between 1800 and extract(year from current_date) + 5),
                constraint properties_floors_sane     check (total_floors is null or floor_number is null or floor_number <= total_floors),
                constraint properties_published_has_timestamp check (
                    status <> 'published' or published_at is not null
                ),
                -- Land has no rooms, bathrooms or floors. Cheap rule, prevents
                -- nonsense listings.
                constraint properties_land_has_no_rooms check (
                    type <> 'land' or (rooms is null and bathrooms is null and floor_number is null)
                ),

                unique (id, tenant_id)
            );

            create unique index properties_reference_key on app.properties (tenant_id, reference);

            -- NFR-1.2 hot path. The composite covers the overwhelmingly common
            -- query shape: "published listings in this tenant, of this listing
            -- type, newest first".
            create index properties_browse_idx
                on app.properties (tenant_id, listing_type, published_at desc)
                where status = 'published' and deleted_at is null;

            create index properties_owner_idx    on app.properties (tenant_id, owner_id) where deleted_at is null;
            create index properties_location_idx on app.properties (location_id)         where status = 'published';
            create index properties_price_idx    on app.properties (tenant_id, price_currency, price_amount) where status = 'published';
            create index properties_type_idx     on app.properties (tenant_id, type)     where status = 'published';

            -- Geospatial (UC-017 radius, UC-025 nearest).
            create index properties_geo_idx on app.properties using gist (coordinates) where status = 'published';

            create trigger properties_touch
                before update on app.properties
                for each row execute function app.touch_updated_at();

            comment on column app.properties.reference is
              'Human-readable listing reference shown to users and printed on contracts. Generated by the application, unique per tenant.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.properties;
            drop type if exists app.property_status;
            drop type if exists app.listing_type;
            drop type if exists app.property_type;
        SQL);
    }
};
