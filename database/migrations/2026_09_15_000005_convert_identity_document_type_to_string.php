<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The document type is no longer supplied with the upload — it is copied
     * from the user's profile (app.users.document_type), which is free text.
     * The closed enum would reject anything outside its four values, so the
     * column follows the profile and becomes a plain varchar.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.identity_documents
                alter column type type varchar(100) using type::text;

            drop type app.identity_document_type;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.identity_document_type as enum (
                'national_id', 'passport', 'residency_permit', 'commercial_register'
            );

            alter table app.identity_documents
                alter column type type app.identity_document_type using type::app.identity_document_type;
        SQL);
    }
};
