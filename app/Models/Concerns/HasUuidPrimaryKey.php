<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Every table here has `id uuid primary key default app.uuid_generate_v7()`.
 * Eloquent only calls RETURNING to learn a DB-generated key when the model
 * is auto-incrementing, so with `$incrementing = false` a bare create()
 * would leave $model->id null after insert. Setting it before the insert
 * sidesteps that — the DB default is still there as a safety net for
 * anything that inserts outside Eloquent.
 */
trait HasUuidPrimaryKey
{
    public static function bootHasUuidPrimaryKey(): void
    {
        static::creating(function ($model): void {
            $key = $model->getKeyName();

            if (empty($model->{$key})) {
                $model->{$key} = (string) Str::uuid();
            }
        });
    }
}
