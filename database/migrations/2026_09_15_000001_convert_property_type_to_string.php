<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `type` was a closed postgres enum (app.property_type). Product wants
     * free-text property types instead of a fixed list, so the column
     * becomes a plain varchar and the enum type is dropped.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- properties_land_has_no_rooms embeds a `type <> 'land'` literal
            -- compiled against the enum type; it must be dropped before the
            -- column type changes and rebuilt against the new varchar type,
            -- or postgres can't re-validate it mid-ALTER.
            alter table app.properties drop constraint properties_land_has_no_rooms;

            alter table app.properties
                alter column type type varchar(100) using type::text;

            drop type app.property_type;

            alter table app.properties add constraint properties_land_has_no_rooms check (
                type <> 'land' or (rooms is null and bathrooms is null and floor_number is null)
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.properties drop constraint properties_land_has_no_rooms;

            create type app.property_type as enum (
                'apartment', 'house', 'villa', 'land', 'office', 'shop', 'warehouse', 'building', 'farm'
            );

            alter table app.properties
                alter column type type app.property_type using type::app.property_type;

            alter table app.properties add constraint properties_land_has_no_rooms check (
                type <> 'land' or (rooms is null and bathrooms is null and floor_number is null)
            );
        SQL);
    }
};
