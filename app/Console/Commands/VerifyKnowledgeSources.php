<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * BR-24: the AI service only retrieves chunks whose source is verified, so an
 * unverified corpus makes every RAG query return nothing and both agents fail
 * closed. The AI service cannot flip the flag itself — `sources_verified_complete`
 * requires a `verified_by` pointing at app.users, and the `wathiq_ai` role has no
 * select on that table. Verification is a human admin act and stays on this side.
 *
 * The KB admin console (Sprint 7) is the real home for this. Until it exists,
 * this does the same thing from the CLI, recording a named admin as the verifier
 * so the audit trail (NFR-12.3) is honest about who vouched for the law.
 *
 * Deliberately not a seeder: marking sources verified on every fresh database
 * would put a false audit trail in every environment, production included.
 */
class VerifyKnowledgeSources extends Command
{
    protected $signature = 'knowledge:verify-sources
        {--jurisdiction=PS : Jurisdiction code whose sources to verify}
        {--admin= : Email of the admin to record as verifier (defaults to the oldest active admin)}
        {--force : Skip the confirmation prompt (for non-interactive runs)}';

    protected $description = 'Mark a jurisdiction\'s knowledge sources as verified, recording a named admin';

    public function handle(): int
    {
        $code = mb_strtoupper(trim((string) $this->option('jurisdiction')));

        $jurisdiction = DB::table('app.jurisdictions')->where('code', $code)->first();

        if (! $jurisdiction) {
            $this->error("No jurisdiction with code {$code}.");

            return self::FAILURE;
        }

        // Scoped to this jurisdiction and to rows that are not already verified:
        // re-running must never overwrite an existing verifier with a new one.
        $pending = DB::table('knowledge.sources')
            ->where('jurisdiction_id', $jurisdiction->id)
            ->where('is_verified', false)
            ->orderBy('law_type')
            ->get(['id', 'law_type', 'title_ar']);

        if ($pending->isEmpty()) {
            $this->info("All {$code} sources are already verified.");

            return self::SUCCESS;
        }

        $verifier = $this->resolveVerifier();

        if (! $verifier) {
            return self::FAILURE;
        }

        $this->line("About to verify {$pending->count()} source(s) in {$code} as {$verifier->email}:");
        $this->table(
            ['id', 'law_type', 'title_ar'],
            $pending->map(fn ($s) => [$s->id, $s->law_type, $s->title_ar])->all()
        );

        if (! $this->option('force') && ! $this->confirm('Record these as verified?')) {
            $this->line('Aborted, nothing changed.');

            return self::SUCCESS;
        }

        // Update by explicit id list, not by the jurisdiction predicate: the set
        // shown above is the set written, even if a source is added mid-run.
        $updated = DB::table('knowledge.sources')
            ->whereIn('id', $pending->pluck('id')->all())
            ->update([
                'is_verified' => true,
                'verified_by' => $verifier->id,
                'verified_at' => now(),
            ]);

        $this->info("Verified {$updated} source(s) in {$code}.");

        return self::SUCCESS;
    }

    /**
     * Same resolution the KYC command uses: an explicit admin by email, else the
     * oldest active admin. The verifier must be a real admin — this column is the
     * record of who vouched for a body of law, not a system account slot.
     */
    private function resolveVerifier(): ?User
    {
        $explicit = $this->option('admin');

        if ($explicit) {
            $email = mb_strtolower(trim((string) $explicit));

            /** @var User|null $admin */
            $admin = User::where('email', $email)->first();

            if (! $admin) {
                $this->error("No user found with email {$email}.");

                return null;
            }

            if (! $admin->hasRole('admin')) {
                $this->error("{$email} is not an admin. Grant it with `php artisan user:make-admin {$email}`.");

                return null;
            }

            return $admin;
        }

        /** @var User|null $admin */
        $admin = User::whereHas('tenantMemberships', function ($q) {
            $q->where('status', 'active')
                ->whereHas('role', fn ($r) => $r->where('code', 'admin'));
        })->orderBy('created_at')->first();

        if (! $admin) {
            $this->error('No admin exists to record as verifier. Create one with `php artisan user:make-admin {email}`, or pass --admin.');

            return null;
        }

        return $admin;
    }
}
