<?php

namespace App\Services\Locations\Import;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * OpenStreetMap data (ODbL) through the Overpass API. Queries are always
 * bounded — one country's places, or the streets around one city — never
 * the planet; the public instance's usage policy asks for exactly that.
 */
class OverpassClient
{
    /**
     * Runs a query body (everything after the [out:json] settings line) and
     * returns its elements.
     *
     * @return list<array<string, mixed>>
     */
    public function elements(string $body): array
    {
        $query = sprintf('[out:json][timeout:%d];', config('locations.import.overpass.query_timeout')).$body;

        return Http::asForm()
            ->withUserAgent(config('locations.import.user_agent'))
            ->timeout(config('locations.import.timeout'))
            // 429, 502 and 504 are how the public instance says "busy, try later".
            ->retry(3, 10_000, fn ($exception) => $exception instanceof RequestException
                && in_array($exception->response->status(), [429, 502, 504], true))
            ->post(config('locations.import.overpass.url'), ['data' => $query])
            ->throw()
            ->json('elements', []);
    }

    /**
     * An Overpass string literal. Only ever fed values this app produced
     * (ISO codes, config lists), but escaped all the same.
     */
    public static function quote(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }
}
