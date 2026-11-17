<?php

namespace App\Services\Locations\Import;

use App\Models\Area;
use App\Models\City;
use App\Models\Street;
use App\Services\Locations\LocationCache;

/**
 * Named streets (OSM highway=*) around one city, each attached to the
 * nearest of that city's areas. Always one city per run: streets are by
 * far the largest level, and a country-wide street query is exactly the
 * "download everything" this import is designed not to do.
 *
 * A street needs an area. In a city with no imported areas — common where
 * OSM has no neighbourhoods mapped — nothing is imported and the report
 * says why, rather than inventing an area to hang the streets on.
 */
class StreetImporter
{
    public function __construct(
        private readonly OverpassClient $overpass,
        private readonly ChildLocationWriter $writer,
        private readonly LocationCache $cache,
    ) {}

    public function import(City $city, ?int $radiusM = null, ?float $maxDistanceKm = null): ImportReport
    {
        $report = new ImportReport;
        $radius = $radiusM ?? (int) config('locations.import.streets.radius_m');
        $maxKm = $maxDistanceKm ?? (float) config('locations.import.streets.max_distance_km');

        if ($city->latitude === null) {
            $report->skip('city has no coordinates');

            return $report;
        }

        $areas = new NearestPoint(
            Area::query()->where('city_id', $city->id)->whereNotNull('latitude')
                ->toBase()->get(['id', 'latitude', 'longitude'])->map(fn ($row) => (array) $row),
            $maxKm,
        );

        if ($areas->isEmpty()) {
            $report->skip('city has no areas with coordinates — import areas first');

            return $report;
        }

        $elements = $this->overpass->elements($this->query($city, $radius));
        $records = OsmElements::toRecords($elements, $areas, $report, 'no area within max distance');

        $this->writer->write(Street::class, 'area_id', $records, $report);
        $this->cache->flush();

        return $report;
    }

    private function query(City $city, int $radius): string
    {
        $types = implode('|', array_map(fn ($type) => preg_quote($type, '/'), config('locations.import.streets.highway_types')));

        return 'way["highway"~'.OverpassClient::quote("^({$types})$").']["name"]'
            .sprintf('(around:%d,%F,%F);', $radius, $city->latitude, $city->longitude)
            .'out center tags;';
    }
}
