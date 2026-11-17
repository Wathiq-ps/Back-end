<?php

namespace App\Services\Locations\Import;

use App\Models\City;
use App\Models\Country;
use App\Services\Locations\LocationCache;
use Illuminate\Support\Facades\DB;

/**
 * Cities of one country from its GeoNames dump. `name` is GeoNames' own
 * name for the place; name_ar and name_en come from its alternate names in
 * those languages, preferred names first, colloquial and historic ones never.
 *
 * Rows are upserted on (source, external_id) — the geonameid — so a re-run
 * updates in place. A name GeoNames no longer has is kept rather than
 * blanked, so a correction made by hand survives the next import.
 */
class CityImporter
{
    private const CHUNK = 500;

    public function __construct(
        private readonly GeoNamesClient $geonames,
        private readonly LocationCache $cache,
    ) {}

    /**
     * @param  list<string>  $featureCodes
     */
    public function import(
        Country $country,
        array $featureCodes,
        int $minPopulation = 0,
        ?string $placesPath = null,
        ?string $alternateNamesPath = null,
    ): ImportReport {
        $report = new ImportReport;
        $places = $this->selectPlaces($country->iso2, $featureCodes, $minPopulation, $placesPath, $report);

        if ($places !== []) {
            $this->attachNames($places, $country->iso2, $alternateNamesPath);

            DB::transaction(function () use ($places, $country, $report) {
                foreach (array_chunk($places, self::CHUNK, true) as $chunk) {
                    $this->upsert($country, $chunk, $report);
                }
            });
        }

        $this->cache->flush();

        return $report;
    }

    /**
     * @return array<string, array<string, mixed>> keyed by geonameid
     */
    private function selectPlaces(string $iso2, array $featureCodes, int $minPopulation, ?string $path, ImportReport $report): array
    {
        $places = [];

        foreach ($this->geonames->places($iso2, $path) as $row) {
            if ($row['country_code'] !== $iso2 || ! in_array($row['feature_code'], $featureCodes, true)) {
                continue; // Not a city: not counted as a skip, it was never a candidate.
            }

            $population = (int) $row['population'];
            $name = trim($row['name']);

            if ($population < $minPopulation) {
                $report->skip('below minimum population');

                continue;
            }

            if ($name === '' || ! is_numeric($row['latitude']) || ! is_numeric($row['longitude'])) {
                $report->skip('missing name or coordinates');

                continue;
            }

            $places[$row['geonameid']] = [
                'name' => $name,
                'name_ar' => null,
                'name_en' => null,
                'latitude' => round((float) $row['latitude'], 6),
                'longitude' => round((float) $row['longitude'], 6),
                'population' => $population,
            ];
        }

        return $places;
    }

    /**
     * Ranks each candidate name: preferred beats non-preferred, a full name
     * beats a short one, and otherwise the first seen wins.
     */
    private function attachNames(array &$places, string $iso2, ?string $path): void
    {
        $ranks = [];

        foreach ($this->geonames->alternateNames($iso2, $path) as $row) {
            $id = $row['geonameid'];
            $column = match ($row['language']) {
                'ar' => 'name_ar',
                'en' => 'name_en',
                default => null,
            };
            $name = trim($row['name']);

            if ($column === null || ! isset($places[$id]) || $row['colloquial'] || $row['historic'] || $name === '') {
                continue;
            }

            $rank = ($row['preferred'] ? 2 : 0) + ($row['short'] ? 0 : 1);

            if ($rank > ($ranks[$id][$column] ?? -1)) {
                $places[$id][$column] = $name;
                $ranks[$id][$column] = $rank;
            }
        }
    }

    private function upsert(Country $country, array $chunk, ImportReport $report): void
    {
        $existing = City::query()
            ->where('source', 'geonames')
            ->whereIn('external_id', array_map('strval', array_keys($chunk)))
            ->count();

        $rows = [];
        foreach ($chunk as $geonameId => $place) {
            $rows[] = ['country_id' => $country->id, 'source' => 'geonames', 'external_id' => (string) $geonameId, ...$place];
        }

        City::query()->upsert($rows, ['source', 'external_id'], [
            'country_id', 'name', 'latitude', 'longitude', 'population',
            'name_ar' => DB::raw('coalesce(excluded.name_ar, cities.name_ar)'),
            'name_en' => DB::raw('coalesce(excluded.name_en, cities.name_en)'),
        ]);

        $report->created += count($rows) - $existing;
        $report->updated += $existing;
    }
}
