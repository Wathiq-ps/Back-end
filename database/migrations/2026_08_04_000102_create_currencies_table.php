<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `exponent` is the reason this table exists. Never assume 2 — JOD is 3
     * decimal places (1000 fils). Every money column elsewhere resolves its
     * display/rounding exponent from here, never a hardcoded x100.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table app.currencies (
                code        app.currency_code primary key,
                exponent    smallint     not null,
                name_ar     varchar(64)  not null,
                name_en     varchar(64)  not null,
                symbol      varchar(8)   not null,
                is_active   boolean      not null default true,

                constraint currencies_exponent_range check (exponent between 0 and 4)
            );

            insert into app.currencies (code, exponent, name_ar, name_en, symbol) values
                ('ILS', 2, 'شيكل إسرائيلي جديد', 'Israeli New Shekel', '₪'),
                ('JOD', 3, 'دينار أردني',        'Jordanian Dinar',    'د.أ'),
                ('USD', 2, 'دولار أمريكي',       'US Dollar',          '$'),
                ('EUR', 2, 'يورو',               'Euro',               '€');

            comment on column app.currencies.exponent is
              'Number of decimal places. JOD is 3 (1000 fils), not 2. Read this column; never hardcode x100.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop table if exists app.currencies;');
    }
};
