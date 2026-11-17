<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    protected $model = City::class;

    public function definition(): array
    {
        return [
            'country_id' => Country::factory(),
            'name' => fake()->city(),
            'name_ar' => null,
            'name_en' => null,
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'population' => null,
            'source' => 'manual',
            'external_id' => null,
        ];
    }

    public function fromGeoNames(int $geonameId): static
    {
        return $this->state(['source' => 'geonames', 'external_id' => (string) $geonameId]);
    }
}
