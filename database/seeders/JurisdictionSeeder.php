<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors the data inserted by
 * 2026_09_08_150000_seed_palestine_jurisdiction.php — see RoleSeeder for why
 * this duplication is intentional. No Eloquent model exists for jurisdictions
 * yet, so this writes through the query builder rather than introducing one
 * ahead of the feature that needs it.
 *
 * Uniqueness is (country_id, code), not code alone, so that additional
 * jurisdictions can be added under the same country later.
 */
class JurisdictionSeeder extends Seeder
{
    public function run(): void
    {
        $countryId = DB::table('countries')->where('iso2', 'PS')->value('id');

        if ($countryId === null) {
            throw new \RuntimeException('Country PS is missing — run CountrySeeder first.');
        }

        DB::table('jurisdictions')->upsert(
            [
                ['country_id' => $countryId, 'code' => 'PS', 'name_ar' => 'فلسطين', 'name_en' => 'Palestine', 'is_active' => true],
            ],
            ['country_id', 'code'],
            ['name_ar', 'name_en', 'is_active'],
        );
    }
}
