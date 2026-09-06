<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table app.role_permissions (
                role_id       uuid not null references app.roles (id)       on delete cascade,
                permission_id uuid not null references app.permissions (id) on delete cascade,
                primary key (role_id, permission_id)
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop table if exists app.role_permissions;');
    }
};
