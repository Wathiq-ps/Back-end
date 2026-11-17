<?php

namespace App\Http\Requests\Location;

use App\Services\Locations\LocationListQuery;
use App\Support\LocationLocale;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The query parameters every picker list shares. Each level adds its parent
 * id; countries widen the sort options.
 */
class ListLocationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'lang' => ['nullable', 'string', 'in:'.implode(',', config('locations.locales'))],
            'sort' => ['nullable', 'string', 'in:'.implode(',', $this->sorts())],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('locations.per_page.max')],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return list<string>
     */
    protected function sorts(): array
    {
        return ['name', '-name'];
    }

    /**
     * A parent id must be a uuid before `exists` runs: comparing a uuid
     * column with any other string is a Postgres type error (a 500), not a
     * miss. `bail` stops at the first failure so it never gets that far.
     */
    protected function parentRules(string $table): array
    {
        return ['bail', 'required', 'uuid', "exists:{$table},id"];
    }

    public function toListQuery(): LocationListQuery
    {
        return new LocationListQuery(
            search: $this->validated('search'),
            locale: LocationLocale::fromRequest($this),
            sort: $this->validated('sort') ?? 'name',
            perPage: (int) ($this->validated('per_page') ?? config('locations.per_page.default')),
            page: (int) ($this->validated('page') ?? 1),
        );
    }
}
