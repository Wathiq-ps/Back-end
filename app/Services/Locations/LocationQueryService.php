<?php

namespace App\Services\Locations;

use App\Models\Area;
use App\Models\City;
use App\Models\Country;
use App\Models\Street;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The read side of the location picker: one paginated, searchable,
 * localised-and-sorted list per level, always scoped to the direct parent.
 *
 * Ordering, in full:
 *   1. With a search term, rows where it starts a word (or the whole text)
 *      come before rows where it only appears mid-word — "nab" lists
 *      Nablus before a name that merely contains "nab" inside a word.
 *   2. The localised name under that locale's collation, which ignores
 *      punctuation ("‘Ābā" sorts as "Aba"). Arabic falls back name_ar →
 *      name → name_en, English name_en → name → name_ar (countries have no
 *      `name`, so just the other language).
 *   3. id, so rows with equal names keep their order across pages.
 */
class LocationQueryService
{
    private const COUNTRY_COLUMNS = ['id', 'iso2', 'iso3', 'name_ar', 'name_en', 'dial_code'];

    private const CHILD_COLUMNS = ['id', 'name', 'name_ar', 'name_en', 'latitude', 'longitude'];

    public function __construct(private readonly LocationCache $cache) {}

    public function countries(LocationListQuery $query): LengthAwarePaginator
    {
        return $this->paginate('countries', [], $query, fn () => Country::query()
            ->select(self::COUNTRY_COLUMNS), $this->countryNameColumns($query->locale), ['iso2']);
    }

    public function cities(string $countryId, LocationListQuery $query): LengthAwarePaginator
    {
        return $this->paginate('cities', ['country_id' => $countryId], $query, fn () => City::query()
            ->select([...self::CHILD_COLUMNS, 'country_id'])
            ->where('country_id', $countryId));
    }

    public function areas(string $cityId, LocationListQuery $query): LengthAwarePaginator
    {
        return $this->paginate('areas', ['city_id' => $cityId], $query, fn () => Area::query()
            ->select([...self::CHILD_COLUMNS, 'city_id'])
            ->where('city_id', $cityId));
    }

    public function streets(string $areaId, LocationListQuery $query): LengthAwarePaginator
    {
        return $this->paginate('streets', ['area_id' => $areaId], $query, fn () => Street::query()
            ->select([...self::CHILD_COLUMNS, 'area_id'])
            ->where('area_id', $areaId));
    }

    /**
     * One search across several levels at once, for a single search box in
     * front of the picker. Each level is ordered like its own list and cut
     * at $limit; `has_more` says whether that cut dropped anything (one
     * extra row is fetched to know, rather than a count per level).
     *
     * Cities, areas and streets come with their ancestors eager-loaded, so
     * a client can fill every picker level from one chosen result.
     *
     * Unlike the lists, a search with nothing searchable left in it (only
     * punctuation) finds nothing — returning arbitrary rows from every level
     * would not answer anything.
     *
     * @param  list<'countries'|'cities'|'areas'|'streets'>  $types
     * @return array<string, array{items: Collection, has_more: bool}>
     */
    public function searchAll(string $search, string $locale, array $types, int $limit, ?string $countryId = null): array
    {
        $limit = max(1, min($limit, (int) config('locations.search.max_limit')));
        $parameters = [
            'search' => $search, 'locale' => $locale, 'types' => $types, 'limit' => $limit, 'country_id' => $countryId,
            'collation' => config("locations.collations.{$locale}"),
        ];

        $results = $this->cache->remember('search', $parameters, function () use ($search, $locale, $types, $limit, $countryId) {
            $key = $this->searchKey($search);
            $results = [];

            foreach ($types as $type) {
                if ($key === null) {
                    $results[$type] = ['items' => [], 'has_more' => false];

                    continue;
                }

                $builder = $this->searchBuilder($type, $countryId);
                $this->applySearch($builder, $key);
                $this->applyNameSort($builder, $type === 'countries' ? $this->countryNameColumns($locale) : $this->nameColumns($locale), $locale);

                $rows = $builder->orderBy('id')->limit($limit + 1)->get();
                $results[$type] = ['items' => $this->cache->dehydrate($rows->take($limit)->values()), 'has_more' => $rows->count() > $limit];
            }

            return $results;
        });

        return array_map(fn ($group) => ['items' => $this->cache->hydrate($group['items']), 'has_more' => $group['has_more']], $results);
    }

    /**
     * The eager loads select only what a parent reference shows (id and
     * names) plus the foreign key the next level up is matched on.
     */
    private function searchBuilder(string $type, ?string $countryId): Builder
    {
        $citiesInCountry = fn () => City::query()->select('id')->where('country_id', $countryId);

        return match ($type) {
            'countries' => Country::query()
                ->select(self::COUNTRY_COLUMNS)
                ->when($countryId, fn ($q) => $q->where('id', $countryId)),
            'cities' => City::query()
                ->select([...self::CHILD_COLUMNS, 'country_id'])
                ->with('country:id,name_ar,name_en')
                ->when($countryId, fn ($q) => $q->where('country_id', $countryId)),
            'areas' => Area::query()
                ->select([...self::CHILD_COLUMNS, 'city_id'])
                ->with(['city:id,country_id,name,name_ar,name_en', 'city.country:id,name_ar,name_en'])
                ->when($countryId, fn ($q) => $q->whereIn('city_id', $citiesInCountry())),
            'streets' => Street::query()
                ->select([...self::CHILD_COLUMNS, 'area_id'])
                ->with(['area:id,city_id,name,name_ar,name_en', 'area.city:id,country_id,name,name_ar,name_en', 'area.city.country:id,name_ar,name_en'])
                ->when($countryId, fn ($q) => $q->whereIn('area_id', Area::query()->select('id')->whereIn('city_id', $citiesInCountry()))),
            default => throw new InvalidArgumentException("Unknown location type [{$type}]."),
        };
    }

    /**
     * Folds a raw search term through the same app.location_search_key()
     * that builds every search_text column, so both sides of the LIKE are
     * normalised identically. Null when nothing searchable is left
     * (empty, or only punctuation and spaces).
     */
    public function searchKey(?string $search): ?string
    {
        $search = trim((string) $search);

        if ($search === '') {
            return null;
        }

        $key = DB::selectOne('select app.location_search_key(?) as key', [$search])->key;

        return $key === '' ? null : $key;
    }

    /**
     * Every word must appear somewhere in search_text; each is its own
     * trigram-indexable LIKE. Also adds the word-start ranking (ordering
     * rule 1), so this must run before the name sort is applied.
     */
    public function applySearch(Builder $builder, string $key): void
    {
        $words = array_slice(explode(' ', $key), 0, (int) config('locations.search.max_terms'));

        foreach ($words as $word) {
            $builder->where('search_text', 'like', '%'.$this->escapeLike($word).'%');
        }

        $escaped = $this->escapeLike($key);
        $builder->orderByRaw(
            'case when search_text like ? or search_text like ? then 0 else 1 end',
            [$escaped.'%', '% '.$escaped.'%'],
        );
    }

    /**
     * @param  list<string>  $columns  name columns in fallback order
     */
    public function applyNameSort(Builder $builder, array $columns, string $locale, string $direction = 'asc'): void
    {
        $collation = config("locations.collations.{$locale}");

        if (! $collation) {
            throw new InvalidArgumentException("No collation configured for locale [{$locale}].");
        }

        $builder->orderByRaw(sprintf(
            'coalesce(%s) collate %s %s',
            implode(', ', $columns),
            implode('.', array_map(fn ($part) => '"'.$part.'"', explode('.', $collation))),
            $direction === 'desc' ? 'desc' : 'asc',
        ));
    }

    public function nameColumns(string $locale): array
    {
        return $locale === 'ar' ? ['name_ar', 'name', 'name_en'] : ['name_en', 'name', 'name_ar'];
    }

    private function countryNameColumns(string $locale): array
    {
        return $locale === 'ar' ? ['name_ar', 'name_en'] : ['name_en', 'name_ar'];
    }

    /**
     * @param  array<string, string>  $scope  parent filter, part of the cache key
     * @param  \Closure(): Builder  $base
     * @param  list<string>|null  $nameColumns  defaults to nameColumns($locale)
     * @param  list<string>  $extraSorts  columns sortable besides `name`
     */
    private function paginate(
        string $level,
        array $scope,
        LocationListQuery $query,
        \Closure $base,
        ?array $nameColumns = null,
        array $extraSorts = [],
    ): LengthAwarePaginator {
        $direction = str_starts_with($query->sort, '-') ? 'desc' : 'asc';
        $sortField = ltrim($query->sort, '-');

        if ($sortField !== 'name' && ! in_array($sortField, $extraSorts, true)) {
            throw new InvalidArgumentException("Unsupported sort [{$query->sort}] for {$level}.");
        }

        $perPage = max(1, min($query->perPage, (int) config('locations.per_page.max')));
        $page = max(1, $query->page);

        // The collation is part of the key: changing it changes every order.
        $key = [...$scope, ...$query->toArray(), 'per_page' => $perPage, 'page' => $page, 'collation' => config("locations.collations.{$query->locale}")];

        $result = $this->cache->remember($level, $key,
            function () use ($base, $query, $nameColumns, $sortField, $direction, $perPage, $page) {
                $builder = $base();

                if ($key = $this->searchKey($query->search)) {
                    $this->applySearch($builder, $key);
                }

                if ($sortField === 'name') {
                    $this->applyNameSort($builder, $nameColumns ?? $this->nameColumns($query->locale), $query->locale, $direction);
                } else {
                    $builder->orderBy($sortField, $direction);
                }

                $paginator = $builder->orderBy('id')->paginate($perPage, ['*'], 'page', $page);

                return ['items' => $this->cache->dehydrate($paginator->getCollection()), 'total' => $paginator->total()];
            });

        return new LengthAwarePaginator($this->cache->hydrate($result['items']), $result['total'], $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
        ]);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
