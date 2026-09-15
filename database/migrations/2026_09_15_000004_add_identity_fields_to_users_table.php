<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The rest of the profile a user fills in before submitting identity
     * documents. `email` and `phone` already exist on the table, so only
     * these three are new.
     *
     * document_type is free text rather than app.identity_document_type:
     * this column records what the user says they hold, while the reviewed
     * app.identity_documents row keeps the constrained value.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.users add column document_type   varchar(100);
            alter table app.users add column document_number varchar(100);
            alter table app.users add column date_of_birth   date;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table app.users drop column if exists date_of_birth;
            alter table app.users drop column if exists document_number;
            alter table app.users drop column if exists document_type;
        SQL);
    }
};
