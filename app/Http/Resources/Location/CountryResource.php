<?php

namespace App\Http\Resources\Location;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CountryResource extends JsonResource
{
    use LocalizesName;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->localizedName($request),
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'iso2' => $this->iso2,
            'iso3' => $this->iso3,
            'dial_code' => $this->dial_code,
        ];
    }
}
