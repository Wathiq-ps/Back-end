<?php

namespace App\Services\Locations;

/**
 * What one picker list request asks for, already validated. Also the cache
 * key: two requests with equal toArray() get the same cached page.
 */
final readonly class LocationListQuery
{
    public function __construct(
        public ?string $search = null,
        public string $locale = 'en',
        public string $sort = 'name',
        public int $perPage = 20,
        public int $page = 1,
    ) {}

    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'locale' => $this->locale,
            'sort' => $this->sort,
            'per_page' => $this->perPage,
            'page' => $this->page,
        ];
    }
}
