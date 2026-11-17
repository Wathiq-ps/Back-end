<?php

namespace Database\Factories;

use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Country>
 */
class CountryFactory extends Factory
{
    protected $model = Country::class;

    /**
     * The codes never start with P, so they can't collide with the PS/PSE row
     * the countries migration seeds into every database.
     */
    public function definition(): array
    {
        return [
            'iso2' => fake()->unique()->regexify('[A-OQ-Z][A-Z]'),
            'iso3' => fake()->unique()->regexify('[A-OQ-Z][A-Z]{2}'),
            'name_en' => fake()->country(),
            'name_ar' => null,
            'dial_code' => '+'.fake()->numerify('###'),
            'is_supported' => false,
        ];
    }
}
