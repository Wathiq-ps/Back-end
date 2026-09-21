<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * `admin` (granted only via `user:make-admin`), `user` (granted to everyone
 * at registration, see OtpService::grantDefaultRole), and `lawyer` (a
 * declarative label on tenant_memberships — actual lawyer-ness is decided by
 * app.lawyer_credentials.verified_at, see LawyerSeeder). Mirrors the data
 * inserted by 2026_08_04_000203_create_roles_table.php and
 * 2026_09_21_000003_seed_lawyer_role.php. Those migrations are what
 * guarantee these rows exist after a bare `migrate` (no `db:seed`
 * needed) — this seeder exists so the same data is also
 * re-runnable/inspectable via `db:seed`, e.g. for tests or to reconcile a
 * database that was restored without its migration history. Keep both
 * lists in sync if a role is ever added.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['code' => 'admin', 'name_ar' => 'مدير النظام', 'name_en' => 'System Administrator'],
            ['code' => 'user', 'name_ar' => 'مستخدم', 'name_en' => 'User'],
            ['code' => 'lawyer', 'name_ar' => 'محامٍ', 'name_en' => 'Lawyer'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['code' => $role['code']],
                ['name_ar' => $role['name_ar'], 'name_en' => $role['name_en'], 'is_system' => true],
            );
        }
    }
}
