<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Public search/browse results. Unlike PropertyResource, this never includes
 * ownership_documents — that's the owner's internal KYC review state, not
 * something an anonymous searcher should see.
 */
class PropertySearchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'published_at' => $this->published_at,
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
            'price_unit' => $this->price_unit,

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
