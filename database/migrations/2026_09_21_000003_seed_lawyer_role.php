<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026_08_04_000203_create_roles_table.php only seeded 'admin' and 'user' —
 * "only two roles for this stage" was true until PropertyRequestService::accept()
 * introduced lawyer review (lawyer_id + app.lawyer_credentials). This is a
 * declarative label on tenant_memberships (mirrors 'admin'); it isn't itself
 * consulted anywhere — being an actual lawyer is decided by
 * app.lawyer_credentials.verified_at, same as before. RoleSeeder mirrors this
 * insert for re-runnability, the same split every other seeded table uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            insert into app.roles (code, name_ar, name_en) values
                ('lawyer', 'محامٍ', 'Lawyer')
            on conflict (code) do nothing;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared("delete from app.roles where code = 'lawyer';");
    }
};
