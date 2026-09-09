<?php

namespace App\Services;

use App\Exceptions\Auth\AuthorizationFailedException;
use App\Exceptions\Property\PropertyConflictException;
use App\Models\Amenity;
use App\Models\OwnershipDocument;
use App\Models\Property;
use App\Models\PropertyMedia;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * FR-3.x add-property flow. A submitted listing always starts under review
 * (`pending_verification`) — nothing here publishes it; that's a separate,
 * not-yet-built admin action once ownership documents are approved.
 */
class PropertyService
{
    private const DISK = 'local';

    public function publish(User $owner, string $propertyId): Property
    {
        $tenantId = Tenant::where('slug', 'default')->value('id');

        abort_if(! $tenantId, 500, 'Default tenant not configured');

        $property = Property::where('id', $propertyId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $property) {
            abort(404);
        }

        if ($property->owner_id !== $owner->id) {
            throw AuthorizationFailedException::forbidden();
        }

        if ($property->status !== 'draft') {
            abort(400, 'Property can only be published from draft status');
        }

        if ($property->published_at === null) {
            $property->published_at = now();
        }

        $property->status = 'published';
        $property->save();

        return $property->fresh();
    }

    /**
     * @param  array<int, UploadedFile>  $photos
     * @param  array<int, UploadedFile>  $ownershipDocuments
     */
    public function create(User $owner, array $data, array $photos, array $ownershipDocuments): Property
    {
        $tenantId = Tenant::where('slug', 'default')->value('id');

        abort_if(! $tenantId, 500, 'Default tenant not configured.');

        $exponent = DB::table('currencies')->where('code', $data['price_currency'])->value('exponent');

        abort_if(is_null($exponent), 422, 'Unsupported currency.');

        $priceMinor = (int) round($data['price'] * (10 ** $exponent));

        $this->assertNotDuplicate($owner, $data, $tenantId);

        return DB::transaction(function () use ($owner, $data, $photos, $ownershipDocuments, $tenantId, $priceMinor) {
            $property = Property::create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'reference' => $this->generateReference($tenantId),
                'owner_id' => $owner->id,
                'title' => $this->generateTitle($data),
                'description' => $data['description'] ?? null,
                'type' => $data['type'],
                'listing_type' => $data['listing_type'],
                'status' => 'pending_verification',
                'price_amount' => $priceMinor,
                'price_currency' => $data['price_currency'],
                'price_unit' => $data['price_unit'] ?? null,
                'area_sqm' => $data['area_sqm'],
                'rooms' => $data['rooms'] ?? null,
                'bathrooms' => $data['bathrooms'] ?? null,
                'floor_number' => $data['floor_number'] ?? null,
                'is_furnished' => $data['is_furnished'] ?? false,
                'location_id' => null,
                'address_line' => $this->generateAddressLine($data),
                'city' => $data['city'],
                'district' => $data['district'],
                'building_number' => $data['building_number'] ?? null,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
            ]);

            $this->attachAmenities($property, $data['features'] ?? []);
            $this->storePhotos($property, $photos);
            $this->storeOwnershipDocuments($property, $owner, $ownershipDocuments, $data['ownership_document_type']);

            return $property->fresh(['media', 'ownershipDocuments', 'amenities']);
        });
    }

    /**
     * @throws PropertyConflictException
     */
    private function assertNotDuplicate(User $owner, array $data, string $tenantId): void
    {
        $isDuplicate = Property::where('tenant_id', $tenantId)
            ->where('owner_id', $owner->id)
            ->where('status', 'pending_verification')
            ->where('type', $data['type'])
            ->where('listing_type', $data['listing_type'])
            ->where('city', $data['city'])
            ->where('district', $data['district'])
            ->where('building_number', $data['building_number'] ?? null)
            ->where('area_sqm', $data['area_sqm'])
            ->exists();

        if ($isDuplicate) {
            throw PropertyConflictException::duplicatePendingSubmission();
        }
    }

    private function generateReference(string $tenantId): string
    {
        do {
            $reference = 'PR-'.strtoupper(Str::random(8));
        } while (Property::where('tenant_id', $tenantId)->where('reference', $reference)->exists());

        return $reference;
    }

    private function generateTitle(array $data): string
    {
        $type = ucfirst($data['type']);
        $listing = $data['listing_type'] === 'sale' ? 'for sale' : 'for rent';

        return "{$type} {$listing} in {$data['city']}";
    }

    private function generateAddressLine(array $data): ?string
    {
        $parts = array_filter([$data['building_number'] ?? null, $data['district'], $data['city']]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function attachAmenities(Property $property, array $codes): void
    {
        if ($codes === []) {
            return;
        }

        $amenityIds = Amenity::whereIn('code', $codes)->pluck('id')->all();

        if ($amenityIds !== []) {
            $property->amenities()->attach($amenityIds, ['tenant_id' => $property->tenant_id]);
        }
    }

    /**
     * @param  array<int, UploadedFile>  $photos
     */
    private function storePhotos(Property $property, array $photos): void
    {
        $seenChecksums = [];
        $sortOrder = 0;

        foreach ($photos as $photo) {
            $checksum = hash_file('sha256', $photo->getRealPath());

            // Same file uploaded twice to one listing is a duplicate, not
            // two photos (see property_media_checksum_key).
            if (isset($seenChecksums[$checksum])) {
                continue;
            }

            $seenChecksums[$checksum] = true;

            $path = Storage::disk(self::DISK)->putFile("properties/{$property->id}", $photo);
            $dimensions = @getimagesize($photo->getRealPath()) ?: [null, null];

            PropertyMedia::create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $property->tenant_id,
                'property_id' => $property->id,
                'disk' => self::DISK,
                'path' => $path,
                'mime_type' => $photo->getMimeType(),
                'size_bytes' => $photo->getSize(),
                'width' => $dimensions[0],
                'height' => $dimensions[1],
                'checksum' => $checksum,
                'is_cover' => $sortOrder === 0,
                'sort_order' => $sortOrder,
            ]);

            $sortOrder++;
        }
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    private function storeOwnershipDocuments(
        Property $property,
        User $owner,
        array $files,
        string $type,
    ): void {
        foreach ($files as $file) {
            $path = Storage::disk(self::DISK)->putFile("properties/{$property->id}/ownership", $file);

            OwnershipDocument::create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $property->tenant_id,
                'property_id' => $property->id,
                'uploaded_by' => $owner->id,
                'type' => $type,
                'path' => $path,
                'checksum' => hash_file('sha256', $file->getRealPath()),
                'status' => 'pending',
            ]);
        }
    }
}
