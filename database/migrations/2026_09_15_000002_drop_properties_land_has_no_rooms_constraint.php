<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Land listings may now optionally carry rooms/bathrooms/floor_number
     * (previously forced to NULL) — product wants these left to the
     * caller rather than enforced by a blanket rule.
     */
    public function up(): void
    {
        DB::unprepared(
            'alter table app.properties drop constraint properties_land_has_no_rooms;'
        );
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.properties add constraint properties_land_has_no_rooms check (
                type <> 'land' or (rooms is null and bathrooms is null and floor_number is null)
            );
        SQL);
    }
};
