<?php

namespace App\Http\Requests\Location;

use Illuminate\Foundation\Http\FormRequest;

class SearchLocationsRequest extends FormRequest
{
    public const TYPES = ['countries', 'cities', 'areas', 'streets'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * ?types=cities,areas reads better in a URL than types[]=…; both work.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->query('types'))) {
            $this->merge(['types' => array_values(array_filter(array_map('trim', explode(',', $this->query('types')))))]);
        }
    }

    public function rules(): array
    {
        return [
            'search' => ['required', 'string', 'max:100'],
            'lang' => ['nullable', 'string', 'in:'.implode(',', config('locations.locales'))],
            'types' => ['nullable', 'array', 'min:1'],
            'types.*' => ['string', 'distinct', 'in:'.implode(',', self::TYPES)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('locations.search.max_limit')],
            // Same reasoning as ListLocationsRequest::parentRules().
            'country_id' => ['bail', 'nullable', 'uuid', 'exists:countries,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'country_id.uuid' => 'The country_id must be a valid UUID.',
            'country_id.exists' => 'No country exists with this country_id.',
        ];
    }

    /**
     * Requested types in the hierarchy's own order, whatever order the
     * client listed them in.
     *
     * @return list<string>
     */
    public function types(): array
    {
        $requested = $this->validated('types') ?? self::TYPES;

        return array_values(array_intersect(self::TYPES, $requested));
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? config('locations.search.default_limit'));
    }
}
