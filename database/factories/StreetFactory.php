<?php

namespace Database\Factories;

use App\Models\Area;
use App\Models\Street;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Street>
 */
class StreetFactory extends Factory
{
    protected $model = Street::class;

    /**
     * unique(): app.streets rejects two streets with the same name in one area.
     */
    public function definition(): array
    {
        return [
            'area_id' => Area::factory(),
            'name' => fake()->unique()->streetName(),
            'name_ar' => null,
            'name_en' => null,
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'source' => 'manual',
            'external_id' => null,
        ];
    }
}
