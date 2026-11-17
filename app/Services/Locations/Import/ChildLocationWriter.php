<?php

namespace App\Services\Locations\Import;

use App\Models\Area;
use App\Models\Street;
use Illuminate\Support\Facades\DB;

/**
 * Writes imported areas or streets idempotently. Each record is matched to
 * an existing row first by (source, external_id), then by its normalised
 * name under the same parent — the same key the unique index enforces — so
 * a re-run updates rows instead of adding them, and a row someone added by
 * hand is linked to its OSM element rather than duplicated.
 *
 * Within one batch, records that normalise to the same name under the same
 * parent collapse into the first: OSM splits a street into many ways, and
 * often maps a neighbourhood as both a node and a polygon.
 *
 * Names are normalised by app.location_search_key() in the database, the
 * same function behind the generated name_key column, so this can never
 * disagree with the unique index about what counts as a duplicate.
 *
 * Writes go through the query builder (no model events, so no cache flush
 * per row); the importers flush once at the end.
 */
class ChildLocationWriter
{
    private const CHUNK = 500;

    /**
     * @param  class-string<Area|Street>  $model
     * @param  list<array{parent_id: string, name: string, name_ar: ?string, name_en: ?string, latitude: float, longitude: float, source: string, external_id: string}>  $records
     */
    public function write(string $model, string $parentColumn, array $records, ImportReport $report): void
    {
        if ($records === []) {
            return;
        }

        // Sorted so the record that survives a merge is always the same one
        // (the lowest OSM id), whatever order Overpass returned them in —
        // otherwise every re-run would flip the row between segments.
        usort($records, fn ($a, $b) => strnatcmp($a['external_id'], $b['external_id']));
        $keys = $this->searchKeys(array_column($records, 'name'));
        $incoming = [];

        foreach ($records as $i => $record) {
            if ($keys[$i] === '') {
                $report->skip('name has no letters or digits');

                continue;
            }

            $slot = $record['parent_id'].'|'.$keys[$i];

            if (isset($incoming[$slot])) {
                $report->skip('merged with a same-named one nearby');

                continue;
            }

            $incoming[$slot] = $record;
        }

        DB::transaction(function () use ($model, $parentColumn, $incoming, $report) {
            [$byExternal, $byName] = $this->existing($model, $parentColumn, $incoming);
            $inserts = [];

            foreach ($incoming as $slot => $record) {
                $match = $byExternal[$record['source'].'|'.$record['external_id']] ?? $byName[$slot] ?? null;

                if (! $match) {
                    $inserts[] = [$parentColumn => $record['parent_id'], ...$this->attributes($record)];
                    $byName[$slot] = (object) ['id' => null];
                    $report->created++;

                    continue;
                }

                $taken = $byName[$slot] ?? null;

                if ($taken && $taken->id !== $match->id) {
                    // Renaming or moving the matched row would collide with
                    // another row that already holds this name.
                    $report->skip('name already used by another row');

                    continue;
                }

                $changes = $this->changes($match, $parentColumn, $record);

                if ($changes === []) {
                    $report->unchanged++;

                    continue;
                }

                $model::query()->whereKey($match->id)->update($changes);
                unset($byName[$match->{$parentColumn}.'|'.$match->name_key]);
                $byName[$slot] = $match;
                $report->updated++;
            }

            foreach (array_chunk($inserts, self::CHUNK) as $chunk) {
                $model::query()->insert($chunk);
            }
        });
    }

    /**
     * Existing rows that any incoming record could match, indexed both ways.
     *
     * @return array{0: array<string, object>, 1: array<string, object>}
     */
    private function existing(string $model, string $parentColumn, array $incoming): array
    {
        $parents = array_values(array_unique(array_column($incoming, 'parent_id')));
        $externals = array_values(array_unique(array_column($incoming, 'external_id')));
        $columns = ['id', $parentColumn, 'name', 'name_ar', 'name_en', 'latitude', 'longitude', 'source', 'external_id', 'name_key'];

        $rows = $model::query()->toBase()
            ->select($columns)
            ->where(fn ($q) => $q->whereIn($parentColumn, $parents)->orWhereIn('external_id', $externals))
            ->orderBy('id')
            ->get();

        $byExternal = [];
        $byName = [];

        foreach ($rows as $row) {
            if ($row->external_id !== null) {
                $byExternal[$row->source.'|'.$row->external_id] ??= $row;
            }
            $byName[$row->{$parentColumn}.'|'.$row->name_key] = $row;
        }

        return [$byExternal, $byName];
    }

    /**
     * What to write over a matched row. Optional names and the source link
     * are only ever filled in, never blanked or re-pointed: a row first
     * imported from one OSM element keeps that link even when a second,
     * same-named element turns up later.
     */
    private function changes(object $row, string $parentColumn, array $record): array
    {
        $wanted = [
            $parentColumn => $record['parent_id'],
            'name' => $record['name'],
            'name_ar' => $record['name_ar'] ?? $row->name_ar,
            'name_en' => $record['name_en'] ?? $row->name_en,
            'latitude' => $record['latitude'],
            'longitude' => $record['longitude'],
        ];

        if ($row->external_id === null) {
            $wanted['source'] = $record['source'];
            $wanted['external_id'] = $record['external_id'];
        }

        return array_filter($wanted, function ($value, $column) use ($row) {
            $current = $row->{$column};

            return in_array($column, ['latitude', 'longitude'], true)
                ? $current === null || round((float) $current, 6) !== round((float) $value, 6)
                : $current !== $value;
        }, ARRAY_FILTER_USE_BOTH);
    }

    private function attributes(array $record): array
    {
        return [
            'name' => $record['name'],
            'name_ar' => $record['name_ar'],
            'name_en' => $record['name_en'],
            'latitude' => $record['latitude'],
            'longitude' => $record['longitude'],
            'source' => $record['source'],
            'external_id' => $record['external_id'],
        ];
    }

    /**
     * app.location_search_key() of each name, in input order, a chunk of
     * names per query.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function searchKeys(array $names): array
    {
        $keys = [];

        foreach (array_chunk($names, self::CHUNK) as $chunk) {
            $rows = DB::select(
                'select app.location_search_key(t.name) as key
                   from jsonb_array_elements_text(?::jsonb) with ordinality as t(name, position)
                  order by t.position',
                [json_encode($chunk, JSON_UNESCAPED_UNICODE)],
            );

            foreach ($rows as $row) {
                $keys[] = (string) $row->key;
            }
        }

        return $keys;
    }
}
