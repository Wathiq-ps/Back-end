<?php

namespace App\Services\Locations\Import;

use App\Models\Area;
use App\Models\City;
use App\Models\Country;
use App\Services\Locations\LocationCache;

/**
 * Areas (OSM place=suburb/neighbourhood/quarter/borough) inside one country,
 * each attached to the nearest of that country's imported cities.
 *
 * OSM has no reliable "this neighbourhood belongs to that GeoNames city"
 * link, and boundary polygons would need PostGIS, so attachment is by
 * distance. A place more than max_distance_km from every city is skipped,
 * not forced onto a far one. Near the edge between two towns the nearest
 * centre can be the wrong town; docs/locations.md lists this limitation.
 */
class AreaImporter
{
    public function __construct(
        private readonly OverpassClient $overpass,
        private readonly ChildLocationWriter $writer,
        private readonly LocationCache $cache,
    ) {}

    /**
     * $near narrows the query to the surroundings of one city — for a large
     * country, import areas a city at a time instead of all at once. Areas
     * found there are still attached to whichever city is nearest.
     */
    public function import(Country $country, ?City $near = null, ?float $maxDistanceKm = null): ImportReport
    {
        $report = new ImportReport;
        $maxKm = $maxDistanceKm ?? (float) config('locations.import.areas.max_distance_km');

        $cities = new NearestPoint(
            City::query()->where('country_id', $country->id)->whereNotNull('latitude')
                ->toBase()->get(['id', 'latitude', 'longitude'])->map(fn ($row) => (array) $row),
            $maxKm,
        );

        if ($cities->isEmpty()) {
            $report->skip('country has no cities with coordinates — import cities first');

            return $report;
        }

        $elements = $this->overpass->elements($this->query($country, $near, $maxKm));
        $records = OsmElements::toRecords($elements, $cities, $report, 'no city within max distance');

        $this->writer->write(Area::class, 'city_id', $records, $report);
        $this->cache->flush();

        return $report;
    }

    private function query(Country $country, ?City $near, float $maxKm): string
    {
        $places = implode('|', array_map(fn ($type) => preg_quote($type, '/'), config('locations.import.areas.place_types')));
        $around = $near && $near->latitude !== null
            ? sprintf('(around:%d,%F,%F)', $maxKm * 1000, $near->latitude, $near->longitude)
            : '';

        return 'area["ISO3166-1"='.OverpassClient::quote($country->iso2).']["type"="boundary"]->.country;'
            .'nwr["place"~'.OverpassClient::quote("^({$places})$").']["name"](area.country)'.$around.';'
            .'out center tags;';
    }
}
