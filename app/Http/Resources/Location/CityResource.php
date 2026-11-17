<?php

namespace App\Http\Resources\Location;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CityResource extends JsonResource
{
    use LocalizesName;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'country_id' => $this->country_id,
            'name' => $this->localizedName($request),
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'country' => $this->ancestor($request, ['country']),
        ];
    }
}
