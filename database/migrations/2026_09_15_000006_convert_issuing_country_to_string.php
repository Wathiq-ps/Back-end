<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The issuing country is now typed in as free text rather than picked
     * from app.countries — the frontend has no country picker wired to that
     * table. Dropped and re-added rather than converted: the old values were
     * uuids, which carry no meaning as strings.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.identity_documents drop column issuing_country_id;
            alter table app.identity_documents add column issuing_country varchar(100);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.identity_documents drop column if exists issuing_country;
            alter table app.identity_documents
                add column issuing_country_id uuid references app.countries (id);
        SQL);
    }
};
