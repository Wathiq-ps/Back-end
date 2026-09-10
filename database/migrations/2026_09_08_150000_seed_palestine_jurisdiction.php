<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026_08_04_000104_create_jurisdictions_table.php creates the table but
 * inserts no rows, unlike the countries and roles migrations. That leaves a
 * freshly migrated database with zero jurisdictions, and
 * knowledge.{sources,kb_versions,chunks}.jurisdiction_id are all NOT NULL
 * references to it — so the AI service cannot write a single row until one
 * exists. Deploys run `migrate --force` without `db:seed`, so the row has to
 * be guaranteed here; JurisdictionSeeder mirrors it for re-runnability, the
 * same split roles and countries already use.
 *
 * One jurisdiction for now: the governing real-estate law applies uniformly
 * across Palestine. The table is keyed on (country_id, code) rather than code
 * alone, so territory-specific bodies of law can be added later as extra rows
 * without a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            insert into app.jurisdictions (country_id, code, name_ar, name_en, is_active)
            select id, 'PS', 'فلسطين', 'Palestine', true
            from app.countries
            where iso2 = 'PS'
            on conflict (country_id, code) do update set is_active = true;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            delete from app.jurisdictions j
            using app.countries c
            where j.country_id = c.id and c.iso2 = 'PS' and j.code = 'PS';
        SQL);
    }
};
