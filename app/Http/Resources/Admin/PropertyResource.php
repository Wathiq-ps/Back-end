<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Admin-facing listing view. Everything the public PropertyResource shows,
 * plus the two things an admin reviewing a submission actually needs: who
 * owns it, and the review state of each ownership document with an
 * authenticated URL to open the file (never a raw storage path).
 */
class PropertyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'submitted_at' => $this->created_at,
            'published_at' => $this->published_at,

            'owner' => [
                'id' => $this->owner?->id,
                'name' => $this->owner?->name,
                'email' => $this->owner?->email,
                'phone' => $this->owner?->phone,
            ],

            'listing_type' => $this->listing_type,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,

            'city' => $this->city,
            'district' => $this->district,
            'building_number' => $this->building_number,
            'address_line' => $this->address_line,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,

            'area_sqm' => (float) $this->area_sqm,
            'price' => $this->resolvePriceMajor(),
            'price_currency' => $this->price_currency,
            'price_unit' => $this->price_unit,

            'rooms' => $this->rooms,
            'bathrooms' => $this->bathrooms,
            'floor_number' => $this->floor_number,
            'is_furnished' => $this->is_furnished,

            'features' => $this->whenLoaded('amenities', fn () => $this->amenities->pluck('code')->values()),

            'photos' => $this->whenLoaded('media', fn () => $this->media->map(fn ($media) => [
                'id' => $media->id,
                'is_cover' => $media->is_cover,
                'sort_order' => $media->sort_order,
            ])->values()),

            // The review payload. Deliberately the same shape the queue in
            // Admin\PropertyController::documents() returns per row, minus the
            // parent property it is already nested under.
            'ownership_documents' => $this->whenLoaded('ownershipDocuments', fn () => $this->ownershipDocuments->map(fn ($document) => [
                'id' => $document->id,
                'type' => $document->type,
                'document_number' => $document->document_number,
                'issued_on' => $document->issued_on,
                'status' => $document->status,
                'rejection_reason' => $document->rejection_reason,
                'submitted_at' => $document->created_at,
                'reviewed_at' => $document->reviewed_at,
                'reviewer' => $document->reviewed_by ? [
                    'id' => $document->reviewer?->id,
                    'email' => $document->reviewer?->email,
                ] : null,
                'file_url' => route('admin.properties.documents.file', ['ownershipDocument' => $document->id]),
            ])->values()),
        ];
    }

    /**
     * price_amount is minor units; the exponent is per-currency (JOD is 3, not
     * 2 — see app.currencies), never hardcoded.
     */
    private function resolvePriceMajor(): float
    {
        $exponent = DB::table('currencies')->where('code', $this->price_currency)->value('exponent') ?? 2;

        return round($this->price_amount / (10 ** $exponent), $exponent);
    }
}
