<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors the data inserted by 2026_08_04_000103_create_countries_table.php
 * — see RoleSeeder for why this duplication is intentional. No Eloquent
 * model exists for countries yet, so this writes through the query
 * builder rather than introducing one ahead of the feature that needs it.
 */
class CountrySeeder extends Seeder
{
    public function run(): void
    {
        DB::table('countries')->upsert(
            [
                ['iso2' => 'PS', 'iso3' => 'PSE', 'name_ar' => 'فلسطين', 'name_en' => 'Palestine', 'dial_code' => '+970', 'is_supported' => true],
            ],
            ['iso2'],
            ['iso3', 'name_ar', 'name_en', 'dial_code', 'is_supported'],
        );
    }
}
