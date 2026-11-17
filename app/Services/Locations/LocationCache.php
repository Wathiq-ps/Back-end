<?php

namespace App\Services\Locations;

use App\Models\Area;
use App\Models\City;
use App\Models\Country;
use App\Models\Street;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * Every cached picker result is keyed under one shared version. flush()
 * replaces the version, which orphans every entry at once — no tag support
 * needed (the default database store has none), and no list of keys to
 * track. The orphans expire on their own TTL.
 *
 * The version is a random string rather than a counter: Cache::increment on
 * a missing key behaves differently per store, and a counter that resets
 * after a cache clear could resurrect old entries under a reused number.
 */
class LocationCache
{
    private const VERSION_KEY = 'locations:version';

    private const MODELS = [Country::class, City::class, Area::class, Street::class];

    public function remember(string $scope, array $parameters, Closure $callback): mixed
    {
        if (! config('locations.cache.enabled')) {
            return $callback();
        }

        ksort($parameters);
        $key = 'locations:'.$this->version().':'.$scope.':'.md5(json_encode($parameters));

        return Cache::remember($key, (int) config('locations.cache.ttl'), $callback);
    }

    /**
     * Models as plain arrays, for storing in the cache. config/cache.php
     * sets serializable_classes to false, so the cache unserializes no
     * objects at all: a cached Eloquent collection would come back as a
     * __PHP_Incomplete_Class. Only arrays and scalars survive the round trip.
     *
     * @return list<array{class: string, attributes: array, relations: array}>
     */
    public function dehydrate(EloquentCollection $models): array
    {
        return $models->map(fn (Model $model) => $this->dehydrateModel($model))->all();
    }

    /**
     * The inverse of dehydrate(). Only the location models can be rebuilt,
     * so a tampered cache entry can't name some other class to instantiate.
     */
    public function hydrate(array $rows): EloquentCollection
    {
        return new EloquentCollection(array_map(fn (array $row) => $this->hydrateModel($row), $rows));
    }

    private function dehydrateModel(Model $model): array
    {
        return [
            'class' => $model::class,
            'attributes' => $model->getAttributes(),
            'relations' => array_map(
                fn ($related) => $related instanceof Model ? $this->dehydrateModel($related) : null,
                $model->getRelations(),
            ),
        ];
    }

    private function hydrateModel(array $row): Model
    {
        if (! in_array($row['class'], self::MODELS, true)) {
            throw new UnexpectedValueException("Refusing to hydrate [{$row['class']}] from the location cache.");
        }

        $model = (new $row['class'])->newFromBuilder($row['attributes']);

        foreach ($row['relations'] as $name => $related) {
            $model->setRelation($name, $related === null ? null : $this->hydrateModel($related));
        }

        return $model;
    }

    public function flush(): void
    {
        Cache::forever(self::VERSION_KEY, (string) Str::uuid());
    }

    private function version(): string
    {
        return Cache::rememberForever(self::VERSION_KEY, fn () => (string) Str::uuid());
    }
}
