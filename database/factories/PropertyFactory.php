<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    protected $model = Property::class;

    private const CITIES = ['Ramallah', 'Nablus', 'Hebron', 'Bethlehem', 'Jenin', 'Tulkarem', 'Jericho', 'Qalqilya'];

    private const CURRENCY_EXPONENTS = ['ILS' => 2, 'JOD' => 3, 'USD' => 2];

    /**
     * Defaults to `pending_verification`, the same status every property
     * starts at in the real create() flow — see the status()-based state
     * methods below for the rest of the lifecycle.
     */
    public function definition(): array
    {
        $type = fake()->randomElement(['apartment', 'house', 'villa', 'land', 'office', 'shop', 'warehouse', 'building', 'farm']);
        $listingType = fake()->randomElement(['sale', 'rent']);
        $city = fake()->randomElement(self::CITIES);
        $currency = fake()->randomElement(array_keys(self::CURRENCY_EXPONENTS));
        $priceMajor = fake()->numberBetween(200, 500000);
        $isLand = $type === 'land';
        $floorNumber = $isLand ? null : fake()->numberBetween(0, 10);

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => Tenant::where('slug', 'default')->value('id'),
            'reference' => 'PR-'.strtoupper(Str::random(8)),
            'owner_id' => User::factory(),
            'title' => ucfirst($type).' '.($listingType === 'sale' ? 'for sale' : 'for rent')." in {$city}",
            'description' => fake()->paragraph(),
            'type' => $type,
            'listing_type' => $listingType,
            'status' => 'pending_verification',
            'price_amount' => (int) round($priceMajor * (10 ** self::CURRENCY_EXPONENTS[$currency])),
            'price_currency' => $currency,
            'price_unit' => $listingType === 'rent' ? fake()->randomElement(['per_month', 'per_year', 'per_week', 'per_day', 'per_hour']) : null,
            'area_sqm' => fake()->randomFloat(2, 40, 500),
            'rooms' => $isLand ? null : fake()->numberBetween(1, 6),
            'bathrooms' => $isLand ? null : fake()->numberBetween(1, 4),
            'floor_number' => $floorNumber,
            'total_floors' => $isLand ? null : fake()->numberBetween($floorNumber, $floorNumber + 10),
            'year_built' => $isLand ? null : fake()->numberBetween(1980, 2024),
            'is_furnished' => $isLand ? false : fake()->boolean(30),
            'location_id' => null,
            'address_line' => fake()->streetAddress().', '.$city,
            'city' => $city,
            'district' => fake()->citySuffix().' District',
            'building_number' => (string) fake()->numberBetween(1, 200),
            'latitude' => fake()->latitude(29.5, 33.3),
            'longitude' => fake()->longitude(34.2, 35.6),
            'published_at' => null,
            'archived_at' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => 'draft']);
    }

    public function pendingVerification(): static
    {
        return $this->state(['status' => 'pending_verification']);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'published',
            'published_at' => now()->subDays(fake()->numberBetween(1, 60)),
        ]);
    }

    /**
     * Entered when a property request is accepted on an already-published
     * listing (see the app.property_status migration comment).
     */
    public function underContract(): static
    {
        return $this->state(fn (array $attributes) => [
            'listing_type' => $attributes['listing_type'] ?? fake()->randomElement(['sale', 'rent']),
            'status' => 'under_contract',
            'published_at' => now()->subDays(fake()->numberBetween(30, 90)),
        ]);
    }

    public function sold(): static
    {
        return $this->state(fn (array $attributes) => [
            'listing_type' => 'sale',
            'price_unit' => null,
            'status' => 'sold',
            'published_at' => now()->subDays(fake()->numberBetween(60, 180)),
        ]);
    }

    public function rented(): static
    {
        return $this->state(fn (array $attributes) => [
            'listing_type' => 'rent',
            'price_unit' => $attributes['price_unit'] ?? fake()->randomElement(['per_month', 'per_year']),
            'status' => 'rented',
            'published_at' => now()->subDays(fake()->numberBetween(60, 180)),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => 'rejected']);
    }
}
