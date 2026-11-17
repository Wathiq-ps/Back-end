<?php

namespace App\Http\Requests\Location;

class ListCitiesRequest extends ListLocationsRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'country_id' => $this->parentRules('countries')];
    }

    public function messages(): array
    {
        return [
            'country_id.required' => 'A country_id is required to list cities.',
            'country_id.uuid' => 'The country_id must be a valid UUID.',
            'country_id.exists' => 'No country exists with this country_id.',
        ];
    }
}
