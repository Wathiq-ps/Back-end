<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The one jurisdiction the MVP serves, pinned by code (config app.jurisdiction,
 * default PS). Contracts, lawyer licences and the AI's knowledge base all key
 * on it, and the AI finds the active law for a contract by this id — so it
 * must never be "whichever jurisdictions row Postgres returns first", which is
 * what three call sites used to take.
 */
final class Jurisdiction
{
    public static function defaultId(): ?string
    {
        return DB::table('jurisdictions')
            ->where('code', config('app.jurisdiction'))
            ->where('is_active', true)
            ->value('id');
    }
}
