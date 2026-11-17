<?php

namespace App\Models\Concerns;

use App\Services\Locations\LocationCache;

/**
 * Any Eloquent write to a location level invalidates every cached picker
 * list. Bulk imports write through the query builder, which fires no model
 * events, so the importers flush explicitly when they finish.
 */
trait FlushesLocationCache
{
    public static function bootFlushesLocationCache(): void
    {
        $flush = fn () => app(LocationCache::class)->flush();

        static::saved($flush);
        static::deleted($flush);
    }
}
