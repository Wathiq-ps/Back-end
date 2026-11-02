<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Public property page. The top-rated card (rating aggregate + public owner
 * card) plus the pin for the map. The address is already public in search,
 * so the coordinates add no exposure. Ownership documents stay out: they
 * are for the owner and admins only.
 */
class PropertyDetailResource extends TopRatedPropertyResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ]);
    }
}
