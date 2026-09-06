<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table app.amenities (
                id       uuid primary key default app.uuid_generate_v7(),
                code     varchar(48)  not null,
                name_ar  varchar(96)  not null,
                name_en  varchar(96)  not null,
                icon     varchar(64),
                is_active boolean     not null default true
            );

            create unique index amenities_code_key on app.amenities (code);

            create table app.property_amenities (
                property_id uuid not null,
                tenant_id   uuid not null,
                amenity_id  uuid not null references app.amenities (id) on delete cascade,

                primary key (property_id, amenity_id),
                foreign key (property_id, tenant_id)
                    references app.properties (id, tenant_id) on delete cascade
            );

            create index property_amenities_amenity_idx on app.property_amenities (amenity_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.property_amenities;
            drop table if exists app.amenities;
        SQL);
    }
};
