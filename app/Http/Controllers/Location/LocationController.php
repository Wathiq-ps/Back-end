<?php

namespace App\Http\Controllers\Location;

use App\Http\Controllers\Controller;
use App\Http\Requests\Location\ListAreasRequest;
use App\Http\Requests\Location\ListCitiesRequest;
use App\Http\Requests\Location\ListCountriesRequest;
use App\Http\Requests\Location\ListStreetsRequest;
use App\Http\Requests\Location\SearchLocationsRequest;
use App\Http\Resources\Location\AreaResource;
use App\Http\Resources\Location\CityResource;
use App\Http\Resources\Location\CountryResource;
use App\Http\Resources\Location\StreetResource;
use App\Services\Locations\LocationQueryService;
use App\Support\LocationLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The country → city → area → street picker (docs/locations.md). Each list
 * is scoped to one parent, which the form request has already confirmed
 * exists, so an unknown parent is a 422 on that field rather than an empty
 * list that looks like missing data.
 */
class LocationController extends Controller
{
    public function __construct(private readonly LocationQueryService $locations) {}

    public function countries(ListCountriesRequest $request): JsonResponse
    {
        return $this->respond(CountryResource::class, $this->locations->countries($request->toListQuery()));
    }

    public function cities(ListCitiesRequest $request): JsonResponse
    {
        return $this->respond(CityResource::class, $this->locations->cities($request->validated('country_id'), $request->toListQuery()));
    }

    public function areas(ListAreasRequest $request): JsonResponse
    {
        return $this->respond(AreaResource::class, $this->locations->areas($request->validated('city_id'), $request->toListQuery()));
    }

    public function streets(ListStreetsRequest $request): JsonResponse
    {
        return $this->respond(StreetResource::class, $this->locations->streets($request->validated('area_id'), $request->toListQuery()));
    }

    /**
     * One search box across the hierarchy. Results are grouped by level
     * under `data`, only for the levels asked for; `meta.has_more` says per
     * level whether more matches exist beyond `limit`.
     */
    public function search(SearchLocationsRequest $request): JsonResponse
    {
        $results = $this->locations->searchAll(
            $request->validated('search'),
            LocationLocale::fromRequest($request),
            $request->types(),
            $request->limit(),
            $request->validated('country_id'),
        );

        $resources = [
            'countries' => CountryResource::class,
            'cities' => CityResource::class,
            'areas' => AreaResource::class,
            'streets' => StreetResource::class,
        ];

        return response()->json([
            'data' => collect($results)->map(fn ($group, $type) => $resources[$type]::collection($group['items'])),
            'meta' => [
                'limit' => $request->limit(),
                'has_more' => collect($results)->map(fn ($group) => $group['has_more']),
            ],
        ]);
    }

    /**
     * @param  class-string<JsonResource>  $resource
     */
    private function respond(string $resource, LengthAwarePaginator $page): JsonResponse
    {
        return response()->json([
            'data' => $resource::collection($page->getCollection()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
