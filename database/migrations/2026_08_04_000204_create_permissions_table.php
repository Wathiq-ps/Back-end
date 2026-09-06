<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table app.permissions (
                id          uuid primary key default app.uuid_generate_v7(),
                code        varchar(64)  not null,
                description varchar(255) not null,

                constraint permissions_code_format check (code ~ '^[a-z_]+\.[a-z_]+$')
            );

            create unique index permissions_code_key on app.permissions (code);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop table if exists app.permissions;');
    }
};
