<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table app.countries (
                id           uuid primary key default app.uuid_generate_v7(),
                iso2         char(2)      not null,
                iso3         char(3)      not null,
                name_ar      varchar(128) not null,
                name_en      varchar(128) not null,
                dial_code    varchar(8)   not null,
                is_supported boolean      not null default false,
                created_at   timestamptz  not null default now(),

                constraint countries_iso2_format check (iso2 ~ '^[A-Z]{2}$'),
                constraint countries_iso3_format check (iso3 ~ '^[A-Z]{3}$')
            );

            create unique index countries_iso2_key on app.countries (iso2);
            create unique index countries_iso3_key on app.countries (iso3);

            insert into app.countries (iso2, iso3, name_ar, name_en, dial_code, is_supported) values
                ('PS', 'PSE', 'فلسطين', 'Palestine', '+970', true);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop table if exists app.countries;');
    }
};
