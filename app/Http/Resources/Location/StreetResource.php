<?php

namespace App\Http\Resources\Location;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StreetResource extends JsonResource
{
    use LocalizesName;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'area_id' => $this->area_id,
            'name' => $this->localizedName($request),
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'area' => $this->ancestor($request, ['area']),
            'city' => $this->ancestor($request, ['area', 'city']),
            'country' => $this->ancestor($request, ['area', 'city', 'country']),
        ];
    }
}
