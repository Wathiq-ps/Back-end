<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Role administration is Phase 2 (see app.roles migration comment) — there
 * is no invite-an-admin endpoint yet, so this is the only way to grant the
 * admin role in the MVP. The user must already exist (i.e. have completed
 * an OTP login at least once).
 */
class MakeAdmin extends Command
{
    protected $signature = 'user:make-admin {email}';

    protected $description = 'Grant the admin role, in the default tenant, to an existing user';

    public function handle(): int
    {
        $email = mb_strtolower(trim($this->argument('email')));

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email {$email}. They must sign in via OTP at least once first.");

            return self::FAILURE;
        }

        $tenant = Tenant::where('slug', 'default')->first();

        if (! $tenant) {
            $this->error('No default tenant found. Run `php artisan db:seed --class=TenantSeeder` first.');

            return self::FAILURE;
        }

        $adminRole = Role::where('code', 'admin')->first();

        if (! $adminRole) {
            $this->error('No "admin" role found. Has the roles migration run?');

            return self::FAILURE;
        }

        $existing = TenantMembership::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('role_id', $adminRole->id)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            $this->info("{$email} is already an admin of {$tenant->slug}.");

            return self::SUCCESS;
        }

        TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $adminRole->id,
            'status' => 'active',
        ]);

        $this->info("Granted admin on tenant '{$tenant->slug}' to {$email}.");

        return self::SUCCESS;
    }
}
