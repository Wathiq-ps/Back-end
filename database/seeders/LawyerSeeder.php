<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A single ready-to-use, verified lawyer account for manual/API testing of
 * the lawyer_id step in PropertyRequestService::accept() — there is no
 * lawyer onboarding flow yet, so this is the only way to get one outside of
 * hand-writing rows. No Eloquent model exists for lawyer_credentials yet, so
 * that table is written through the query builder (see JurisdictionSeeder
 * for the same reasoning). Being an actual lawyer is decided by
 * app.lawyer_credentials.verified_at — the 'lawyer' tenant_membership role
 * granted below is a declarative label, not itself checked anywhere yet.
 */
class LawyerSeeder extends Seeder
{
    private const EMAIL = 'lawer@gmail.com';

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'default')->first();

        if (! $tenant) {
            throw new RuntimeException('No default tenant found. Run TenantSeeder first.');
        }

        $lawyerRole = Role::where('code', 'lawyer')->first();

        if (! $lawyerRole) {
            throw new RuntimeException('No "lawyer" role found. Has the roles migration / RoleSeeder run?');
        }

        $jurisdictionId = DB::table('jurisdictions')->value('id');

        if (! $jurisdictionId) {
            throw new RuntimeException('No jurisdiction found. Run JurisdictionSeeder first.');
        }

        User::updateOrCreate(
            ['email' => self::EMAIL],
            ['name' => 'Test Lawyer', 'status' => 'active', 'locale' => 'ar', 'email_verified_at' => now()],
        );

        // Re-fetch rather than trust the model returned above: when this
        // seeder runs via DatabaseSeeder (WithoutModelEvents), the
        // `creating` event HasUuidPrimaryKey relies on never fires, so the
        // in-memory model's id would be null even though the DB default
        // filled it in — same gotcha PropertySeeder documents.
        $user = User::where('email', self::EMAIL)->firstOrFail();

        TenantMembership::firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $lawyerRole->id],
            ['status' => 'active'],
        );

        DB::table('lawyer_credentials')->upsert(
            [[
                'user_id' => $user->id,
                'license_number' => 'LIC-TEST-'.strtoupper(Str::substr(str_replace('-', '', $user->id), 0, 10)),
                'bar_association' => 'Palestine Bar Association',
                'jurisdiction_id' => $jurisdictionId,
                'issued_at' => now()->subYears(3)->toDateString(),
                'verified_at' => now(),
                'document_path' => "lawyers/{$user->id}/license.pdf",
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['user_id'],
            ['bar_association', 'jurisdiction_id', 'issued_at', 'verified_at', 'document_path', 'updated_at'],
        );

        $this->command?->info("Verified lawyer ready: {$user->email} ({$user->id})");
    }
}
