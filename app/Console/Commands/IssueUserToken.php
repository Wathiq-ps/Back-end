<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Console\Command;

/**
 * Mints a token pair without going through the OTP flow, for test accounts
 * whose mailbox nobody can actually read (see --create). Deliberately
 * CLI-only: anyone who can run artisan already has database access, so this
 * grants no privilege that wasn't there already — but it must never be
 * exposed over HTTP.
 */
class IssueUserToken extends Command
{
    protected $signature = 'user:token {email} {--create : Create the account, already active and verified, if it does not exist yet}';

    protected $description = 'Issue an access + refresh token pair for a user, bypassing OTP';

    public function handle(JwtService $jwt): int
    {
        $email = mb_strtolower(trim($this->argument('email')));

        /** @var User|null $user */
        $user = User::where('email', $email)->first();

        if (! $user && ! $this->option('create')) {
            $this->error("No user found with email {$email}. Pass --create to make one.");

            return self::FAILURE;
        }

        if (! $user) {
            $user = $this->createVerifiedUser($email);

            if (! $user) {
                return self::FAILURE;
            }

            $this->info("Created {$email}.");
        }

        $accessToken = $jwt->issueAccessToken($user);

        // Tagged in place of a real user agent so these sessions stay
        // identifiable — and revocable — in app.user_sessions later.
        $refresh = $jwt->issueRefreshToken($user, null, 'artisan user:token');

        $this->newLine();
        $this->line("User: {$user->id}  ({$email}, {$user->status})");
        $this->newLine();
        $this->line('Access token (expires in '.config('jwt.access_ttl').'s):');
        $this->line($accessToken);
        $this->newLine();
        $this->line('Refresh token (expires in '.config('jwt.refresh_ttl').'s):');
        $this->line($refresh['token']);
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Mirrors OtpService: a new account gets the default `user` role in the
     * single MVP tenant. Unlike the OTP path it lands active and verified
     * straight away — the whole point is that no one will read this mailbox.
     */
    private function createVerifiedUser(string $email): ?User
    {
        $tenant = Tenant::where('slug', 'default')->first();

        if (! $tenant) {
            $this->error('No default tenant found. Run `php artisan db:seed --class=TenantSeeder --force` first.');

            return null;
        }

        $role = Role::where('code', 'user')->first();

        if (! $role) {
            $this->error('No "user" role found. Has the roles migration run?');

            return null;
        }

        /** @var User $user */
        $user = User::create([
            'email' => $email,
            'locale' => 'en',
        ]);

        // status is not mass-assignable (see the Fillable attribute on User),
        // and the OTP path is what would normally flip these.
        $user->forceFill([
            'status' => 'active',
            'email_verified_at' => now(),
        ])->save();

        TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return $user;
    }
}
