<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Shared column-level domains, used across nearly every module.
     *
     * Money is (BIGINT minor units, ISO-4217 code) — never a float. The
     * minor-unit exponent is per-currency and lives in app.currencies
     * (see 2026_08_04_000101_create_currencies_table.php); it is NOT always 2.
     * Palestine circulates ILS (2), USD (2) and JOD (3) — a hardcoded x100
     * silently divides every JOD amount by ten, on legal instruments.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create domain app.money_minor as bigint
              constraint money_minor_non_negative check (value >= 0);

            create domain app.money_signed as bigint;   -- ledger deltas only

            create domain app.currency_code as char(3)
              constraint currency_code_format check (value ~ '^[A-Z]{3}$');

            comment on domain app.money_minor is
              'Monetary amount in minor units. Pair with a currency_code column; resolve the exponent via app.currencies.';

            create domain app.email as citext
              constraint email_format check (value ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$');

            -- E.164
            create domain app.phone as varchar(20)
              constraint phone_format check (value ~ '^\+[1-9][0-9]{7,14}$');

            create domain app.sha256_hex as char(64)
              constraint sha256_hex_format check (value ~ '^[0-9a-f]{64}$');

            create type app.locale as enum ('ar', 'en');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop type if exists app.locale;
            drop domain if exists app.sha256_hex;
            drop domain if exists app.phone;
            drop domain if exists app.email;
            drop domain if exists app.currency_code;
            drop domain if exists app.money_signed;
            drop domain if exists app.money_minor;
        SQL);
    }
};
