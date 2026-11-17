<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The location picker's sort order (config locations.collations).
     *
     * The stock ICU collations sort punctuation before letters, so GeoNames
     * transliterations that open with an ayn mark — "‘Ābā", "‘Abdah" — all
     * landed at the top of the list instead of among the A's. `ka-shifted`
     * makes punctuation and spaces ignorable for ordering, so "‘Ābā" sorts as
     * "Aba" and "Al-Bireh" as "Al Bireh". Ties are still broken
     * deterministically (and by id in the query), so paging stays stable.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create collation app.location_en (provider = icu, locale = 'en-u-ka-shifted');
            create collation app.location_ar (provider = icu, locale = 'ar-u-ka-shifted');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop collation if exists app.location_ar;
            drop collation if exists app.location_en;
        SQL);
    }
};
