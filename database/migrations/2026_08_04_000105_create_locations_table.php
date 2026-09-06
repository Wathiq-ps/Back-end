<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * FR-13.6, UC-034. Adjacency list plus a materialised path. The path
     * denormalises the ancestor chain so a subtree filter ("everything in
     * Gaza governorate") is one index range scan instead of a recursive CTE
     * per search request — this sits directly on the NFR-1.2 hot path.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.location_level as enum ('country', 'governorate', 'city', 'area');

            create table app.locations (
                id          uuid primary key default app.uuid_generate_v7(),
                parent_id   uuid         references app.locations (id) on delete restrict,
                country_id  uuid         not null references app.countries (id) on delete restrict,
                level       app.location_level not null,
                name_ar     varchar(191) not null,
                name_en     varchar(191) not null,
                path        text         not null default '',
                centroid    geography(Point, 4326),
                is_active   boolean      not null default true,
                created_at  timestamptz  not null default now(),
                updated_at  timestamptz  not null default now(),

                -- Only a country-level node may be rootless.
                constraint locations_root_is_country check (
                    (parent_id is null and level = 'country') or
                    (parent_id is not null and level <> 'country')
                )
            );

            create index locations_parent_idx  on app.locations (parent_id);
            create index locations_country_idx on app.locations (country_id);
            create index locations_path_idx    on app.locations (path text_pattern_ops);
            create unique index locations_sibling_name_key
                on app.locations (coalesce(parent_id, '00000000-0000-0000-0000-000000000000'::uuid), name_ar);

            create or replace function app.maintain_location_path()
            returns trigger
            language plpgsql
            as $$
            declare
              parent_path text := '';
            begin
                if new.parent_id is not null then
                    select path into parent_path from app.locations where id = new.parent_id;
                end if;
                new.path := parent_path || '/' || new.id::text;
                return new;
            end;
            $$;

            create trigger locations_path
                before insert or update of parent_id on app.locations
                for each row execute function app.maintain_location_path();

            create trigger locations_touch
                before update on app.locations
                for each row execute function app.touch_updated_at();

            comment on column app.locations.path is
              'Materialised ancestor path, "/uuid/uuid/...". Subtree filter: path LIKE ''/<ancestor>/%''.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.locations;
            drop function if exists app.maintain_location_path();
            drop type if exists app.location_level;
        SQL);
    }
};
