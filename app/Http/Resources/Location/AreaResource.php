<?php

namespace App\Http\Resources\Location;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AreaResource extends JsonResource
{
    use LocalizesName;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'city_id' => $this->city_id,
            'name' => $this->localizedName($request),
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'city' => $this->ancestor($request, ['city']),
            'country' => $this->ancestor($request, ['city', 'country']),
        ];
    }
}
