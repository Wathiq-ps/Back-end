<?php

namespace App\Services;

use App\Exceptions\Lawyer\LawyerCredentialConflictException;
use App\Models\LawyerCredential;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * FR-13.5. A lawyer registers like any other user (OTP, same as everyone) —
 * this is the extra step that proves the licence, reviewed by an admin before
 * the account can be assigned to a contract. Deliberately shaped like
 * KycService: same submit → queue → approve/reject lifecycle, same
 * never-a-public-URL posture for the uploaded file.
 */
class LawyerCredentialService
{
    private const DISK = 'local';

    /**
     * @throws LawyerCredentialConflictException
     */
    public function submit(User $user, array $data, UploadedFile $document): LawyerCredential
    {
        $existing = LawyerCredential::find($user->id);

        if ($existing?->status === 'approved') {
            throw LawyerCredentialConflictException::alreadyVerified();
        }

        if (in_array($existing?->status, ['pending', 'under_review'], true)) {
            throw LawyerCredentialConflictException::reviewPending();
        }

        $jurisdictionId = DB::table('jurisdictions')->value('id');
        abort_if(! $jurisdictionId, 500, 'No jurisdiction configured.');

        // lawyer_credentials_license_key makes this unique per jurisdiction;
        // checking first turns "that licence belongs to someone else" into a
        // 409 instead of a constraint-violation 500.
        $licenseTaken = LawyerCredential::where('jurisdiction_id', $jurisdictionId)
            ->where('license_number', $data['license_number'])
            ->where('user_id', '!=', $user->id)
            ->exists();

        if ($licenseTaken) {
            throw LawyerCredentialConflictException::licenseTaken();
        }

        $path = Storage::disk(self::DISK)->putFile("lawyers/{$user->id}", $document);

        // A rejected submission is replaced in place — user_id is the primary
        // key, so there is only ever one row. Every field from the previous
        // decision is cleared so nothing stale survives into the new review.
        return tap(LawyerCredential::updateOrCreate(
            ['user_id' => $user->id],
            [
                'license_number' => $data['license_number'],
                'bar_association' => $data['bar_association'],
                'jurisdiction_id' => $jurisdictionId,
                'issued_at' => $data['issued_at'],
                'expires_at' => $data['expires_at'] ?? null,
                'document_path' => $path,
                'status' => 'pending',
                'verified_at' => null,
                'verified_by' => null,
                'reviewed_at' => null,
                'reviewed_by' => null,
                'rejection_reason' => null,
            ],
        ), fn (LawyerCredential $credential) => $credential->refresh());
    }

    /**
     * Approving is what makes the account usable as a lawyer: verified_at is
     * the column PropertyRequestService::accept() tests, and the one
     * EnsureLawyerIsApproved waits for. The 'lawyer' role isn't touched here
     * — it was granted at registration and says what the account signed up
     * as, not what it is cleared to do.
     */
    public function approve(LawyerCredential $credential, User $reviewer): LawyerCredential
    {
        $credential->forceFill([
            'status' => 'approved',
            'verified_at' => now(),
            'verified_by' => $reviewer->id,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer->id,
            'rejection_reason' => null,
        ])->save();

        return $credential;
    }

    /**
     * verified_at is cleared, not just left alone: an admin reversing an
     * earlier approval has to revoke the thing accept() actually checks, and
     * lawyer_credentials_verified_when_approved enforces that anyway.
     */
    public function reject(LawyerCredential $credential, User $reviewer, string $reason): LawyerCredential
    {
        $credential->forceFill([
            'status' => 'rejected',
            'verified_at' => null,
            'verified_by' => null,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer->id,
            'rejection_reason' => $reason,
        ])->save();

        return $credential;
    }

    /**
     * @return array{contents: string, mime: string}
     */
    public function readDocument(LawyerCredential $credential): array
    {
        $disk = Storage::disk(self::DISK);

        abort_unless($credential->document_path && $disk->exists($credential->document_path), 404);

        return [
            'contents' => $disk->get($credential->document_path),
            'mime' => $disk->mimeType($credential->document_path) ?: 'application/octet-stream',
        ];
    }
}
