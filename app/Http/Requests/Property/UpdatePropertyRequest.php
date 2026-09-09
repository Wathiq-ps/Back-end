<?php

namespace App\Http\Requests\Property;

use App\Models\Property;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Every field is `sometimes`: only what the caller actually sends gets
     * validated (and later written) — everything else stays exactly as it
     * is in the database. Fields not in this list (address_line, ownership
     * documents, ...) aren't editable here at all.
     */
    public function rules(): array
    {
        return [
            'listing_type' => ['sometimes', 'in:sale,rent'],
            'type' => ['sometimes', 'in:apartment,house,villa,land,office,shop,warehouse,building,farm'],

            'city' => ['sometimes', 'string', 'max:128'],
            'district' => ['sometimes', 'string', 'max:128'],
            'building_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],

            'area_sqm' => ['sometimes', 'numeric', 'min:0.01'],
            'price' => ['sometimes', 'numeric', 'gt:0'],
            'price_currency' => ['sometimes', 'in:ILS,JOD,USD'],
            'price_unit' => ['sometimes', 'nullable', 'in:per_month,per_year,per_week,per_day,per_hour'],

            'rooms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'bathrooms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'floor_number' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:200'],

            'features' => ['sometimes', 'array'],
            'features.*' => ['string', 'in:elevator,parking,garden,water,electricity,internet'],
            'is_furnished' => ['sometimes', 'boolean'],

            'description' => ['sometimes', 'nullable', 'string'],

            'photos' => ['sometimes', 'array', 'min:1', 'max:20'],
            'photos.*' => ['image', 'max:8192'],
        ];
    }

    /**
     * Cross-field rules (price_unit vs listing_type, land vs rooms) have to
     * be checked against the *effective* value — the incoming one if the
     * caller is changing it, otherwise whatever is already on the row —
     * since either side of the rule might be the one left unchanged.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $property = Property::find($this->route('id'));

            if (! $property) {
                return; // surfaced as 404 by the controller/service, not a validation error
            }

            $effectiveListingType = $this->input('listing_type', $property->listing_type);
            $effectiveType = $this->input('type', $property->type);
            $effectivePriceUnit = $this->has('price_unit') ? $this->input('price_unit') : $property->price_unit;

            if ($effectiveListingType === 'rent' && $effectivePriceUnit === null) {
                $validator->errors()->add('price_unit', __('The price unit field is required when the listing type is rent.'));
            }

            if ($effectiveListingType === 'sale' && $this->has('price_unit') && $this->input('price_unit') !== null) {
                $validator->errors()->add('price_unit', __('The price unit field must not be set when the listing type is sale.'));
            }

            if ($effectiveType !== 'land') {
                return;
            }

            foreach (['rooms', 'bathrooms', 'floor_number'] as $field) {
                $effectiveValue = $this->has($field) ? $this->input($field) : $property->{$field};

                if ($effectiveValue !== null) {
                    $validator->errors()->add($field, __('Land properties cannot have :field.', ['field' => $field]));
                }
            }
        });
    }
}
