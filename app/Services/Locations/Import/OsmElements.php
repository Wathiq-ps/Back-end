<?php

namespace App\Services\Locations\Import;

/**
 * Turns Overpass elements (fetched with `out center tags`) into location
 * records, and attaches each to the nearest parent. Shared by the area and
 * street importers, which differ only in what they query and what counts
 * as a parent.
 */
class OsmElements
{
    /**
     * `name` is the element's own name tag, which OSM keeps in the local
     * language; name:ar and name:en fill in the rest. An element with
     * neither a name nor a position can't be placed or shown, so it's
     * skipped — never given an invented one.
     *
     * @param  list<array<string, mixed>>  $elements
     * @return list<array<string, mixed>>
     */
    public static function toRecords(array $elements, NearestPoint $parents, ImportReport $report, string $noParentReason): array
    {
        $records = [];

        foreach ($elements as $element) {
            $tags = $element['tags'] ?? [];
            $name = self::tag($tags, 'name') ?? self::tag($tags, 'name:ar') ?? self::tag($tags, 'name:en');
            $latitude = $element['lat'] ?? $element['center']['lat'] ?? null;
            $longitude = $element['lon'] ?? $element['center']['lon'] ?? null;

            if ($name === null || ! is_numeric($latitude) || ! is_numeric($longitude)) {
                $report->skip('missing name or position');

                continue;
            }

            $parentId = $parents->find((float) $latitude, (float) $longitude);

            if ($parentId === null) {
                $report->skip($noParentReason);

                continue;
            }

            $records[] = [
                'parent_id' => $parentId,
                'name' => mb_substr($name, 0, 191),
                'name_ar' => self::limit(self::tag($tags, 'name:ar')),
                'name_en' => self::limit(self::tag($tags, 'name:en')),
                'latitude' => round((float) $latitude, 6),
                'longitude' => round((float) $longitude, 6),
                'source' => 'osm',
                'external_id' => $element['type'].'/'.$element['id'],
            ];
        }

        return $records;
    }

    private static function tag(array $tags, string $key): ?string
    {
        $value = trim((string) ($tags[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    private static function limit(?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, 0, 191);
    }
}
