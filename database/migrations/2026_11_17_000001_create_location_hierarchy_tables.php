<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The country → city → area → street picker (docs/locations.md).
     *
     * One table per level rather than another row in app.locations: each
     * level comes from a different source (REST Countries, GeoNames,
     * OpenStreetMap) with its own identifiers and its own idea of which names
     * exist, and the API filters strictly by direct parent. app.locations is
     * left as it is — it holds no rows and no code reads it.
     *
     * app.countries already exists and is referenced by jurisdictions and
     * user profiles, so it is extended here rather than replaced. Its name_ar
     * and dial_code become optional because the import source does not have
     * them for every country.
     *
     * Every level gets a generated `search_text`: name, name_ar and name_en
     * folded through app.location_search_key(), behind a trigram index. The
     * search service folds the query through the same function — see the
     * warning on normalize_arabic() about doing it on one side only.
     *
     * Duplicates are stopped here, not only in the importers. The
     * (source, external_id) indexes are deliberately not partial: Postgres
     * already lets any number of NULL external_ids through a unique index,
     * and a partial one can't be the target of the importers' ON CONFLICT.
     *   cities   (source, external_id) — the GeoNames id. Two cities in one
     *            country may legitimately share a name.
     *   areas    (source, external_id), and the normalised name within a city
     *            (OSM often maps one neighbourhood as both a node and a polygon).
     *   streets  the normalised name within an area. OSM splits one street
     *            into many ways, so external_id is only the first way's id and
     *            is not unique.
     *
     * Parents are `on delete restrict`: removing a country must never quietly
     * take thousands of streets with it.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Place names arrive as "Al-Bireh", "Al Bireh" and "al bireh";
            -- users type any of them. On top of normalize_arabic() this folds
            -- case and turns every run of punctuation or whitespace into one
            -- space.
            create function app.location_search_key(t text)
            returns text
            language sql
            immutable
            strict
            parallel safe
            as $$
              select btrim(regexp_replace(app.normalize_arabic(lower(t)), '[[:punct:][:space:]]+', ' ', 'g'));
            $$;

            alter table app.countries
                alter column name_ar   drop not null,
                alter column dial_code drop not null,
                add column updated_at  timestamptz not null default now(),
                add column search_text text generated always as (
                    app.location_search_key(name_en || ' ' || coalesce(name_ar, ''))
                ) stored;

            create index countries_search_trgm_idx on app.countries using gin (search_text gin_trgm_ops);

            create trigger countries_touch
                before update on app.countries
                for each row execute function app.touch_updated_at();

            create table app.cities (
                id           uuid primary key default app.uuid_generate_v7(),
                country_id   uuid         not null references app.countries (id) on delete restrict,
                name         varchar(191) not null,
                name_ar      varchar(191),
                name_en      varchar(191),
                latitude     numeric(9, 6),
                longitude    numeric(9, 6),
                population   bigint,
                source       varchar(16)  not null default 'manual',
                external_id  varchar(64),
                search_text  text generated always as (
                    app.location_search_key(name || ' ' || coalesce(name_ar, '') || ' ' || coalesce(name_en, ''))
                ) stored,
                created_at   timestamptz  not null default now(),
                updated_at   timestamptz  not null default now(),

                constraint cities_name_present     check (btrim(name) <> ''),
                constraint cities_source_known     check (source in ('geonames', 'osm', 'manual')),
                constraint cities_coordinates_pair check ((latitude is null) = (longitude is null)),
                constraint cities_latitude_range   check (latitude between -90 and 90),
                constraint cities_longitude_range  check (longitude between -180 and 180),
                constraint cities_population_range check (population >= 0)
            );

            create index cities_country_idx on app.cities (country_id);
            create index cities_search_trgm_idx on app.cities using gin (search_text gin_trgm_ops);
            create unique index cities_source_external_key on app.cities (source, external_id);

            create trigger cities_touch
                before update on app.cities
                for each row execute function app.touch_updated_at();

            create table app.areas (
                id           uuid primary key default app.uuid_generate_v7(),
                city_id      uuid         not null references app.cities (id) on delete restrict,
                name         varchar(191) not null,
                name_ar      varchar(191),
                name_en      varchar(191),
                latitude     numeric(9, 6),
                longitude    numeric(9, 6),
                source       varchar(16)  not null default 'manual',
                external_id  varchar(64),
                name_key     text generated always as (app.location_search_key(name)) stored,
                search_text  text generated always as (
                    app.location_search_key(name || ' ' || coalesce(name_ar, '') || ' ' || coalesce(name_en, ''))
                ) stored,
                created_at   timestamptz  not null default now(),
                updated_at   timestamptz  not null default now(),

                constraint areas_name_present     check (btrim(name) <> ''),
                constraint areas_source_known     check (source in ('geonames', 'osm', 'manual')),
                constraint areas_coordinates_pair check ((latitude is null) = (longitude is null)),
                constraint areas_latitude_range   check (latitude between -90 and 90),
                constraint areas_longitude_range  check (longitude between -180 and 180)
            );

            create unique index areas_city_name_key on app.areas (city_id, name_key);
            create index areas_search_trgm_idx on app.areas using gin (search_text gin_trgm_ops);
            create unique index areas_source_external_key on app.areas (source, external_id);

            create trigger areas_touch
                before update on app.areas
                for each row execute function app.touch_updated_at();

            create table app.streets (
                id           uuid primary key default app.uuid_generate_v7(),
                area_id      uuid         not null references app.areas (id) on delete restrict,
                name         varchar(191) not null,
                name_ar      varchar(191),
                name_en      varchar(191),
                latitude     numeric(9, 6),
                longitude    numeric(9, 6),
                source       varchar(16)  not null default 'manual',
                external_id  varchar(64),
                name_key     text generated always as (app.location_search_key(name)) stored,
                search_text  text generated always as (
                    app.location_search_key(name || ' ' || coalesce(name_ar, '') || ' ' || coalesce(name_en, ''))
                ) stored,
                created_at   timestamptz  not null default now(),
                updated_at   timestamptz  not null default now(),

                constraint streets_name_present     check (btrim(name) <> ''),
                constraint streets_source_known     check (source in ('geonames', 'osm', 'manual')),
                constraint streets_coordinates_pair check ((latitude is null) = (longitude is null)),
                constraint streets_latitude_range   check (latitude between -90 and 90),
                constraint streets_longitude_range  check (longitude between -180 and 180)
            );

            create unique index streets_area_name_key on app.streets (area_id, name_key);
            create index streets_search_trgm_idx on app.streets using gin (search_text gin_trgm_ops);
            create index streets_source_external_idx on app.streets (source, external_id)
                where external_id is not null;

            create trigger streets_touch
                before update on app.streets
                for each row execute function app.touch_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.streets;
            drop table if exists app.areas;
            drop table if exists app.cities;

            drop trigger if exists countries_touch on app.countries;
            drop index if exists app.countries_search_trgm_idx;
            alter table app.countries
                drop column if exists search_text,
                drop column if exists updated_at;
            update app.countries set dial_code = '' where dial_code is null;
            update app.countries set name_ar = name_en where name_ar is null;
            alter table app.countries
                alter column dial_code set not null,
                alter column name_ar   set not null;

            drop function if exists app.location_search_key(text);
        SQL);
    }
};
