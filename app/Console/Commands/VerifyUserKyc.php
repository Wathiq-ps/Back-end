<?php

namespace App\Console\Commands;

use App\Models\IdentityDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Services\KycService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * UC-040 review queue is admin-only over HTTP (see Admin\IdentityDocumentController),
 * which is unusable for test accounts whose mailbox nobody reads. This does the
 * same approval from the CLI so a user clears the `kyc.verified` middleware.
 *
 * Approves a real pending submission when there is one. Only when the user has
 * submitted nothing does it fabricate a placeholder document — that row carries
 * no image files, so the admin image routes will 404 on it. It is a testing
 * shortcut, not a substitute for review.
 */
class VerifyUserKyc extends Command
{
    protected $signature = 'user:verify-kyc
        {email}
        {--reviewer= : Email of the admin to record as reviewer (defaults to any active admin)}
        {--type=national_id : Document type used only when creating a placeholder}';

    protected $description = 'Mark a user KYC-verified by approving their identity document';

    private const TYPES = ['national_id', 'passport', 'residency_permit', 'commercial_register'];

    public function handle(KycService $kyc): int
    {
        $email = mb_strtolower(trim($this->argument('email')));

        /** @var User|null $user */
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email {$email}.");

            return self::FAILURE;
        }

        if ($user->isKycVerified()) {
            $this->info("{$email} is already KYC-verified.");

            return self::SUCCESS;
        }

        $type = (string) $this->option('type');

        if (! in_array($type, self::TYPES, true)) {
            $this->error('Invalid --type. Expected one of: '.implode(', ', self::TYPES).'.');

            return self::FAILURE;
        }

        $reviewer = $this->resolveReviewer();

        if (! $reviewer) {
            return self::FAILURE;
        }

        // A submission already waiting in the queue is the thing to approve —
        // fabricating a second document would trip the one-approved-per-user index.
        /** @var IdentityDocument|null $document */
        $document = IdentityDocument::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'under_review'])
            ->orderByDesc('created_at')
            ->first();

        if ($document) {
            $this->line("Approving the {$document->status} document already on file.");
        } else {
            $document = $this->createPlaceholder($user, $type);

            if (! $document) {
                return self::FAILURE;
            }

            $this->warn('No submission on file — created a placeholder document with no images.');
        }

        $kyc->approve($document, $reviewer);

        $this->info("{$email} is now KYC-verified (reviewer: {$reviewer->email}).");

        return self::SUCCESS;
    }

    /**
     * The review-complete check constraint requires a reviewer on any approved
     * row, so an admin has to exist before this command can do anything.
     */
    private function resolveReviewer(): ?User
    {
        $explicit = $this->option('reviewer');

        if ($explicit) {
            $email = mb_strtolower(trim((string) $explicit));

            /** @var User|null $reviewer */
            $reviewer = User::where('email', $email)->first();

            if (! $reviewer) {
                $this->error("No user found with reviewer email {$email}.");

                return null;
            }

            if (! $reviewer->hasRole('admin')) {
                $this->error("{$email} is not an admin. Grant it with `php artisan user:make-admin {$email}`.");

                return null;
            }

            return $reviewer;
        }

        /** @var User|null $reviewer */
        $reviewer = User::whereHas('tenantMemberships', function ($q) {
            $q->where('status', 'active')
                ->whereHas('role', fn ($r) => $r->where('code', 'admin'));
        })->orderBy('created_at')->first();

        if (! $reviewer) {
            $this->error('No admin exists to record as reviewer. Create one with `php artisan user:make-admin {email}`, or pass --reviewer.');

            return null;
        }

        return $reviewer;
    }

    private function createPlaceholder(User $user, string $type): ?IdentityDocument
    {
        $tenant = Tenant::where('slug', 'default')->first();

        if (! $tenant) {
            $this->error('No default tenant found. Run `php artisan db:seed --class=TenantSeeder --force` first.');

            return null;
        }

        // Created pending and approved through KycService afterwards, so the
        // reviewer/timestamp stamping lives in exactly one place.
        return IdentityDocument::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => $type,
            'document_number' => 'CLI-VERIFIED',
            'front_path' => '',
            'status' => 'pending',
        ]);
    }
}
