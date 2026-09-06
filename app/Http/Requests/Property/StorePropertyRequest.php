<?php

namespace App\Http\Requests\Property;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StorePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'listing_type' => ['required', 'in:sale,rent'],
            'type' => ['required', 'in:apartment,house,villa,land,office,shop,warehouse,building,farm'],

            'city' => ['required', 'string', 'max:128'],
            'district' => ['required', 'string', 'max:128'],
            'building_number' => ['nullable', 'string', 'max:32'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],

            'area_sqm' => ['required', 'numeric', 'min:0.01'],
            'price' => ['required', 'numeric', 'gt:0'],
            'price_currency' => ['required', 'in:ILS,JOD,USD'],

            'rooms' => ['nullable', 'integer', 'min:0', 'max:100'],
            'bathrooms' => ['nullable', 'integer', 'min:0', 'max:100'],
            'floor_number' => ['nullable', 'integer', 'min:0', 'max:200'],

            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'in:elevator,parking,garden,water,electricity,internet'],
            'is_furnished' => ['nullable', 'boolean'],

            'description' => ['nullable', 'string'],

            'photos' => ['required', 'array', 'min:1', 'max:20'],
            'photos.*' => ['image', 'max:8192'],

            'ownership_documents' => ['required', 'array', 'min:1', 'max:10'],
            'ownership_documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:8192'],
            'ownership_document_type' => [
                'required', 'in:title_deed,sale_contract,inheritance_deed,power_of_attorney,municipal_record',
            ],
        ];
    }

    /**
     * Mirrors the DB's `properties_land_has_no_rooms` check — land has no
     * rooms, bathrooms, or floor. Caught here so it's a clean 422 instead
     * of a constraint-violation 500.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('type') !== 'land') {
                return;
            }

            foreach (['rooms', 'bathrooms', 'floor_number'] as $field) {
                if ($this->filled($field)) {
                    $validator->errors()->add($field, __('Land properties cannot have :field.', ['field' => $field]));
                }
            }
        });
    }
}
