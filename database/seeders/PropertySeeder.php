<?php

namespace Database\Seeders;

use App\Models\Amenity;
use App\Models\OwnershipDocument;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo data covering every app.property_status value, for exercising the
 * publish/edit endpoints without hand-building rows through the API first.
 * Each property gets an ownership document consistent with its status —
 * `pending_verification` properties have a document still `pending`
 * review; everything past that point (draft and beyond) has one already
 * `approved`; `rejected` properties have one `rejected`.
 */
class PropertySeeder extends Seeder
{
    private const PROPERTIES_PER_STATUS = 2;

    public function run(): void
    {
        // DatabaseSeeder uses WithoutModelEvents, which disables the
        // `creating` event that HasUuidPrimaryKey relies on to set `id` —
        // Postgres still fills a real UUID via its own column default, but
        // Eloquent never reads it back, so the freshly created models here
        // would have a null `id` in memory. Re-fetching by email (which
        // *is* set correctly in memory) gets fully hydrated rows back.
        $ownerEmails = User::factory()->count(5)->create()->pluck('email');
        $owners = User::whereIn('email', $ownerEmails)->get();
        $reviewer = $owners->first();
        $amenityIds = Amenity::pluck('id')->all();

        $statuses = [
            'pending_verification' => fn () => Property::factory()->pendingVerification(),
            'draft' => fn () => Property::factory()->draft(),
            'published' => fn () => Property::factory()->published(),
            'under_contract' => fn () => Property::factory()->underContract(),
            'sold' => fn () => Property::factory()->sold(),
            'rented' => fn () => Property::factory()->rented(),
            'rejected' => fn () => Property::factory()->rejected(),
        ];

        foreach ($statuses as $status => $factoryState) {
            for ($i = 0; $i < self::PROPERTIES_PER_STATUS; $i++) {
                $property = $factoryState()
                    ->for($owners->random(), 'owner')
                    ->create();

                $this->attachOwnershipDocument($property, $status, $reviewer);

                if ($amenityIds !== [] && $property->type !== 'land') {
                    $property->amenities()->attach(
                        fake()->randomElements($amenityIds, fake()->numberBetween(1, 3)),
                        ['tenant_id' => $property->tenant_id],
                    );
                }
            }
        }
    }

    private function attachOwnershipDocument(Property $property, string $status, User $reviewer): void
    {
        $documentStatus = match ($status) {
            'pending_verification' => 'pending',
            'rejected' => 'rejected',
            default => 'approved', // draft, published, under_contract, sold, rented all imply docs were approved
        };

        OwnershipDocument::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $property->tenant_id,
            'property_id' => $property->id,
            'uploaded_by' => $property->owner_id,
            'type' => fake()->randomElement(['title_deed', 'sale_contract', 'inheritance_deed', 'power_of_attorney', 'municipal_record']),
            'path' => "ownership/{$property->id}/demo-".Str::random(8).'.pdf',
            'checksum' => hash('sha256', Str::random(32)),
            'status' => $documentStatus,
            'reviewed_by' => $documentStatus === 'pending' ? null : $reviewer->id,
            'reviewed_at' => $documentStatus === 'pending' ? null : now(),
            'rejection_reason' => $documentStatus === 'rejected' ? 'Document image is illegible.' : null,
        ]);
    }
}
