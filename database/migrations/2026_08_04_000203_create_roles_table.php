<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * NFR-4.4 RBAC. Roles are seeded here and are not user-editable in the
     * MVP — role/permission administration is Phase 2 (FR-13.2).
     *
     * Only two roles for this stage: `admin` (granted only via
     * `user:make-admin`) and `user` (granted to everyone at registration —
     * a normal user acts as both owner and beneficiary, there is no
     * separate account type for each yet).
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table app.roles (
                id          uuid primary key default app.uuid_generate_v7(),
                code        varchar(32)  not null,
                name_ar     varchar(96)  not null,
                name_en     varchar(96)  not null,
                is_system   boolean      not null default true,
                created_at  timestamptz  not null default now(),

                constraint roles_code_format check (code ~ '^[a-z_]+$')
            );

            create unique index roles_code_key on app.roles (code);

            insert into app.roles (code, name_ar, name_en) values
                ('admin', 'مدير النظام', 'System Administrator'),
                ('user',  'مستخدم',       'User');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop table if exists app.roles;');
    }
};
