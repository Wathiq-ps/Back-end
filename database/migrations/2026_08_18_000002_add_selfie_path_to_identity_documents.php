<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * UC-040 KYC: verifying a person requires two photos, not one — the ID
     * document itself (front_path/back_path) and a live photo of the person
     * holding it, so a reviewer can compare face-to-document. Without a
     * selfie, front_path alone only proves someone possesses a photo of an
     * ID, not that they are the person on it.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.identity_documents
                add column selfie_path text;

            comment on column app.identity_documents.selfie_path is
              'Live photo of the applicant, ideally holding the document, for reviewer face match against front_path.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.identity_documents drop column if exists selfie_path;
        SQL);
    }
};
