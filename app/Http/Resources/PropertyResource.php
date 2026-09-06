<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Never a raw storage path or public URL for media/documents — same
 * "streamed through an authenticated route" posture as IdentityDocumentResource.
 */
class PropertyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'listing_type' => $this->listing_type,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,

            'city' => $this->city,
            'district' => $this->district,
            'building_number' => $this->building_number,
            'address_line' => $this->address_line,

            'area_sqm' => (float) $this->area_sqm,
            'price' => $this->resolvePriceMajor(),
            'price_currency' => $this->price_currency,

            'rooms' => $this->rooms,
            'bathrooms' => $this->bathrooms,
            'floor_number' => $this->floor_number,
            'is_furnished' => $this->is_furnished,

            'features' => $this->amenities->pluck('code')->values(),

            'photos' => $this->media->map(fn ($media) => [
                'id' => $media->id,
                'is_cover' => $media->is_cover,
                'sort_order' => $media->sort_order,
            ])->values(),

            'ownership_documents' => $this->ownershipDocuments->map(fn ($document) => [
                'id' => $document->id,
                'type' => $document->type,
                'status' => $document->status,
            ])->values(),

            'created_at' => $this->created_at,
        ];
    }

    /**
     * price_amount is minor units; the exponent is per-currency (JOD is 3,
     * not 2 — see app.currencies), never hardcoded.
     */
    private function resolvePriceMajor(): float
    {
        $exponent = DB::table('currencies')->where('code', $this->price_currency)->value('exponent') ?? 2;

        return round($this->price_amount / (10 ** $exponent), $exponent);
    }
}
