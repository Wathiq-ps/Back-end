<?php

namespace App\Http\Resources\Location;

use App\Services\Locations\LocationQueryService;
use App\Support\LocationLocale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;

/**
 * `name` in a location response is the display name in the requested
 * language, with the same fallback order the list is sorted by — so the
 * order a picker shows is the alphabetical order of exactly the names it
 * displays. Countries have no `name` column; getAttribute() returns null for
 * it and the loop moves on to the other language.
 */
trait LocalizesName
{
    protected function localizedName(Request $request, ?Model $model = null): ?string
    {
        $model ??= $this->resource;
        $columns = app(LocationQueryService::class)->nameColumns(LocationLocale::fromRequest($request));

        foreach ($columns as $column) {
            if (filled($model->getAttribute($column))) {
                return $model->getAttribute($column);
            }
        }

        return null;
    }

    /**
     * An ancestor as {id, name}, present only when the query eager-loaded
     * it (the global search does; the per-level lists don't need to, the
     * client already chose the parent). Walks $path from this resource, so
     * a street can reach its country through area → city → country without
     * a lazy load along the way.
     *
     * @param  list<string>  $path  relation names, e.g. ['area', 'city']
     */
    protected function ancestor(Request $request, array $path): array|MissingValue
    {
        $model = $this->resource;

        foreach ($path as $relation) {
            if (! $model->relationLoaded($relation) || ! $model->getRelation($relation)) {
                return new MissingValue;
            }
            $model = $model->getRelation($relation);
        }

        return ['id' => $model->getKey(), 'name' => $this->localizedName($request, $model)];
    }
}
