<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Only two roles for this stage: `admin` (granted only via
 * `user:make-admin`) and `user` (granted to everyone at registration, see
 * OtpService::grantDefaultRole). Mirrors the data inserted by
 * 2026_08_04_000203_create_roles_table.php. That migration is what
 * guarantees these rows exist after a bare `migrate` (no `db:seed`
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
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['code' => $role['code']],
                ['name_ar' => $role['name_ar'], 'name_en' => $role['name_en'], 'is_system' => true],
            );
        }
    }
}
