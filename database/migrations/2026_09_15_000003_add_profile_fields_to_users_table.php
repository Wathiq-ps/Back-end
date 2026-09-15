<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * UC-040 prerequisite: a user fills these in on their profile before the
     * KYC upload will accept documents (see KycService::submit).
     *
     * Nullable because accounts are created by OTP alone — email is the only
     * thing known at registration. `nationality` is free text, not a
     * app.countries reference: the frontend sends whatever the user types.
     * `signature_path` is a private storage key, never a public URL, matching
     * how identity documents are handled.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.users add column name           varchar(191);
            alter table app.users add column nationality    varchar(100);
            alter table app.users add column signature_path text;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.users drop column if exists signature_path;
            alter table app.users drop column if exists nationality;
            alter table app.users drop column if exists name;
        SQL);
    }
};
