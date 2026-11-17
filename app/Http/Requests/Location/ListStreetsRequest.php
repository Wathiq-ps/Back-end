<?php

namespace App\Http\Requests\Location;

class ListStreetsRequest extends ListLocationsRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'area_id' => $this->parentRules('areas')];
    }

    public function messages(): array
    {
        return [
            'area_id.required' => 'An area_id is required to list streets.',
            'area_id.uuid' => 'The area_id must be a valid UUID.',
            'area_id.exists' => 'No area exists with this area_id.',
        ];
    }
}
