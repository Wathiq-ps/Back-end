<?php

namespace App\Http\Requests\Location;

class ListAreasRequest extends ListLocationsRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'city_id' => $this->parentRules('cities')];
    }

    public function messages(): array
    {
        return [
            'city_id.required' => 'A city_id is required to list areas.',
            'city_id.uuid' => 'The city_id must be a valid UUID.',
            'city_id.exists' => 'No city exists with this city_id.',
        ];
    }
}
