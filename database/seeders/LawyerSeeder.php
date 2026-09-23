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
            [
                'name' => 'Test Lawyer',
                'status' => 'active',
                'locale' => 'ar',
                'email_verified_at' => now(),
                // A lawyer clears identity KYC like everyone else, and KYC
                // needs a complete profile — without these the account 403s
                // on kyc.verified and isn't actually usable.
                'nationality' => 'Palestinian',
                'document_type' => 'national_id',
                'document_number' => 'TESTLAWYER1',
                'signature_path' => 'signatures/test-lawyer.png',
            ],
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

        // status and verified_at have to move together —
        // lawyer_credentials_verified_when_approved rejects the row otherwise.
        // This skips the real review flow (Lawyer\LawyerCredentialController →
        // Admin\LawyerCredentialController) on purpose: it's a fixture.
        DB::table('lawyer_credentials')->upsert(
            [[
                'user_id' => $user->id,
                'license_number' => 'LIC-TEST-'.strtoupper(Str::substr(str_replace('-', '', $user->id), 0, 10)),
                'bar_association' => 'Palestine Bar Association',
                'jurisdiction_id' => $jurisdictionId,
                'issued_at' => now()->subYears(3)->toDateString(),
                'status' => 'approved',
                'verified_at' => now(),
                'reviewed_at' => now(),
                'document_path' => "lawyers/{$user->id}/license.pdf",
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['user_id'],
            ['bar_association', 'jurisdiction_id', 'issued_at', 'status', 'verified_at', 'reviewed_at', 'document_path', 'updated_at'],
        );

        // Identity KYC, approved. The lawyer routes gate on kyc.verified, so
        // a fixture without this can't reach them. Also skips the real flow
        // (Kyc\IdentityDocumentController → Admin\IdentityDocumentController)
        // on purpose.
        $hasApprovedId = DB::table('identity_documents')
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->exists();

        if (! $hasApprovedId) {
            DB::table('identity_documents')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'type' => $user->document_type,
                'document_number' => $user->document_number,
                'front_path' => "kyc/{$user->id}/front.jpg",
                'selfie_path' => "kyc/{$user->id}/selfie.jpg",
                'status' => 'approved',
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->command?->info("Verified lawyer ready: {$user->email} ({$user->id})");
    }
}
