<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors the data inserted by 2026_08_04_000102_create_currencies_table.php
 * — see RoleSeeder for why this duplication is intentional. No Eloquent
 * model exists for currencies yet, so this writes through the query
 * builder rather than introducing one ahead of the feature that needs it.
 */
class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            ['code' => 'ILS', 'exponent' => 2, 'name_ar' => 'شيكل إسرائيلي جديد', 'name_en' => 'Israeli New Shekel', 'symbol' => '₪'],
            ['code' => 'JOD', 'exponent' => 3, 'name_ar' => 'دينار أردني', 'name_en' => 'Jordanian Dinar', 'symbol' => 'د.أ'],
            ['code' => 'USD', 'exponent' => 2, 'name_ar' => 'دولار أمريكي', 'name_en' => 'US Dollar', 'symbol' => '$'],
            ['code' => 'EUR', 'exponent' => 2, 'name_ar' => 'يورو', 'name_en' => 'Euro', 'symbol' => '€'],
        ];

        DB::table('currencies')->upsert(
            $currencies,
            ['code'],
            ['exponent', 'name_ar', 'name_en', 'symbol'],
        );
    }
}
