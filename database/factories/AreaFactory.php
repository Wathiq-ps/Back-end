<?php

namespace Database\Factories;

use App\Models\Area;
use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Area>
 */
class AreaFactory extends Factory
{
    protected $model = Area::class;

    /**
     * unique(): app.areas rejects two areas with the same name in one city.
     */
    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'name' => ucwords(fake()->unique()->words(2, true)),
            'name_ar' => null,
            'name_en' => null,
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'source' => 'manual',
            'external_id' => null,
        ];
    }
}
