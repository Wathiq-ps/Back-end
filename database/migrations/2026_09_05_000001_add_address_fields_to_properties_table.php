<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * FR-3.x. The map-picker flow: the frontend resolves city/district from
     * its own location APIs and sends plain text, not a app.locations node
     * — that tree has no seeded data yet. `location_id` stays on the table
     * for when it's wired up later, so it's made nullable rather than
     * dropped.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.properties alter column location_id drop not null;

            alter table app.properties add column city            varchar(128) not null;
            alter table app.properties add column district        varchar(128) not null;
            alter table app.properties add column building_number varchar(32);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.properties drop column if exists building_number;
            alter table app.properties drop column if exists district;
            alter table app.properties drop column if exists city;

            alter table app.properties alter column location_id set not null;
        SQL);
    }
};
