<?php

namespace App\Services\Locations\Import;

/**
 * Finds the nearest of a fixed set of points within a maximum distance.
 *
 * The points are bucketed into a grid whose cells are $maxKm tall, so a
 * lookup only measures against the neighbouring cells instead of every
 * point — a country can hold tens of thousands of cities, and an import
 * looks one up per OSM element. No PostGIS needed (it isn't installed).
 */
class NearestPoint
{
    private const EARTH_RADIUS_KM = 6371.0;

    private const KM_PER_DEGREE = 111.32;

    private readonly float $cellDegrees;

    /** @var array<string, list<array{0: string, 1: float, 2: float}>> */
    private array $cells = [];

    /**
     * @param  iterable<array{id: string, latitude: float, longitude: float}>  $points
     */
    public function __construct(iterable $points, private readonly float $maxKm)
    {
        $this->cellDegrees = $maxKm / self::KM_PER_DEGREE;

        foreach ($points as $point) {
            $this->cells[$this->cellKey((float) $point['latitude'], (float) $point['longitude'])][] =
                [(string) $point['id'], (float) $point['latitude'], (float) $point['longitude']];
        }
    }

    public function isEmpty(): bool
    {
        return $this->cells === [];
    }

    /**
     * The id of the nearest point no further than $maxKm away, or null.
     */
    public function find(float $latitude, float $longitude): ?string
    {
        // A degree of longitude shrinks towards the poles, so more cells
        // across are needed to cover the same distance.
        $span = (int) ceil(1 / max(cos(deg2rad($latitude)), 0.01));
        $row = (int) floor($latitude / $this->cellDegrees);
        $column = (int) floor($longitude / $this->cellDegrees);

        $best = null;
        $bestKm = $this->maxKm;

        for ($r = $row - 1; $r <= $row + 1; $r++) {
            for ($c = $column - $span; $c <= $column + $span; $c++) {
                foreach ($this->cells["{$r}:{$c}"] ?? [] as [$id, $lat, $lng]) {
                    $km = $this->distanceKm($latitude, $longitude, $lat, $lng);

                    if ($km <= $bestKm) {
                        [$best, $bestKm] = [$id, $km];
                    }
                }
            }
        }

        return $best;
    }

    private function cellKey(float $latitude, float $longitude): string
    {
        return (int) floor($latitude / $this->cellDegrees).':'.(int) floor($longitude / $this->cellDegrees);
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }
}
