<?php

namespace Database\Seeders;

use App\Models\Amenity;
use Illuminate\Database\Seeder;

/**
 * The property "features list", minus `furnished` — that one is its own
 * `is_furnished` boolean column on properties, not an amenity row (see
 * StorePropertyRequest / PropertyService).
 */
class AmenitySeeder extends Seeder
{
    public function run(): void
    {
        $amenities = [
            ['code' => 'elevator', 'name_ar' => 'مصعد', 'name_en' => 'Elevator'],
            ['code' => 'parking', 'name_ar' => 'موقف سيارات', 'name_en' => 'Parking'],
            ['code' => 'garden', 'name_ar' => 'حديقة', 'name_en' => 'Garden'],
            ['code' => 'water', 'name_ar' => 'مياه', 'name_en' => 'Water'],
            ['code' => 'electricity', 'name_ar' => 'كهرباء', 'name_en' => 'Electricity'],
            ['code' => 'internet', 'name_ar' => 'إنترنت', 'name_en' => 'Internet'],
        ];

        foreach ($amenities as $amenity) {
            Amenity::updateOrCreate(
                ['code' => $amenity['code']],
                ['name_ar' => $amenity['name_ar'], 'name_en' => $amenity['name_en'], 'is_active' => true],
            );
        }
    }
}
