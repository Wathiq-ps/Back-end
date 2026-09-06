<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * NFR-2.4 requires supporting multiple countries and legal systems
     * without a redesign. Jurisdiction is modelled separately from country
     * because a country can hold several applicable bodies of law, and
     * because the knowledge base and contract both reference the
     * jurisdiction, not the country.
     *
     * app.law_type is also reused later by knowledge.sources and
     * knowledge.chunks (see the 000601_* migration).
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.law_type as enum ('sale', 'rent', 'ownership', 'tax', 'general');

            create table app.jurisdictions (
                id            uuid primary key default app.uuid_generate_v7(),
                country_id    uuid         not null references app.countries (id) on delete restrict,
                code          varchar(32)  not null,
                name_ar       varchar(255) not null,
                name_en       varchar(255) not null,
                is_active     boolean      not null default true,
                created_at    timestamptz  not null default now(),

                constraint jurisdictions_code_format check (code ~ '^[A-Z0-9_]+$')
            );

            create unique index jurisdictions_country_code_key on app.jurisdictions (country_id, code);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.jurisdictions;
            drop type if exists app.law_type;
        SQL);
    }
};
