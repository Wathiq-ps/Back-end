<?php

return [
    // Picker lists change only when an import runs or an admin edits a row,
    // and both bump the cache version (LocationCache::flush), so a long TTL
    // costs nothing in freshness. It only bounds how long the entries for a
    // retired version linger in the store.
    'cache' => [
        'enabled' => (bool) env('LOCATIONS_CACHE_ENABLED', true),
        'ttl' => (int) env('LOCATIONS_CACHE_TTL', 60 * 60 * 24), // 1 day
    ],

    'per_page' => [
        'default' => 20,
        'max' => 100,
    ],

    // The first entry is the fallback when a request asks for neither.
    'locales' => ['en', 'ar'],

    // Collations for the localised sort: ICU's English and Arabic orders with
    // punctuation ignored, created by the location sort collations migration.
    // They need a PostgreSQL built with ICU, which the official postgres and
    // pgvector images are.
    'collations' => [
        'en' => 'app.location_en',
        'ar' => 'app.location_ar',
    ],

    // A search term is split on whitespace and every word must match; past
    // this many words the rest are ignored rather than adding more LIKEs.
    // The limits are results per level from the global /locations/search.
    'search' => [
        'max_terms' => 5,
        'default_limit' => 10,
        'max_limit' => 50,
    ],

    // The locations:import-* commands (docs/locations.md).
    'import' => [
        // Sent on every request: the Overpass and GeoNames usage policies
        // ask clients to identify themselves.
        'user_agent' => env('LOCATIONS_IMPORT_USER_AGENT', 'Wathiq location import ('.env('APP_URL', 'localhost').')'),
        'timeout' => (int) env('LOCATIONS_IMPORT_TIMEOUT', 300),

        'geonames' => [
            'base_url' => env('LOCATIONS_GEONAMES_URL', 'https://download.geonames.org/export/dump'),

            // countryInfo.txt still lists these dissolved countries.
            'excluded_countries' => ['AN', 'CS'],

            // Which GeoNames feature codes count as a city. The default is
            // every current populated place; sections of a place (PPLX) and
            // historical, abandoned or destroyed ones are left out. Other
            // codes a country may need — refugee camps are CMPRF, for
            // instance — can be added here or per run with --feature-codes.
            'feature_codes' => [
                'PPL', 'PPLA', 'PPLA2', 'PPLA3', 'PPLA4', 'PPLA5',
                'PPLC', 'PPLF', 'PPLG', 'PPLL', 'PPLR', 'PPLS',
            ],
        ],

        'overpass' => [
            'url' => env('LOCATIONS_OVERPASS_URL', 'https://overpass-api.de/api/interpreter'),
            // The server-side limit written into each query; the HTTP timeout
            // above must stay longer than this.
            'query_timeout' => 180,
        ],

        'areas' => [
            'place_types' => ['suburb', 'neighbourhood', 'quarter', 'borough'],
            // An area is attached to the nearest imported city within this
            // distance of it, or skipped.
            'max_distance_km' => 10,
        ],

        'streets' => [
            'highway_types' => [
                'motorway', 'trunk', 'primary', 'secondary', 'tertiary',
                'unclassified', 'residential', 'living_street', 'pedestrian',
            ],
            // How far from the city's own coordinates to fetch streets.
            'radius_m' => 8000,
            // A street is attached to the nearest area of that city within
            // this distance of it, or skipped.
            'max_distance_km' => 3,
        ],
    ],
];
