<?php

namespace App\Services;

use App\Exceptions\Kyc\KycConflictException;
use App\Exceptions\Kyc\ProfileIncompleteException;
use App\Models\IdentityDocument;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * UC-040. Files are never served by a public URL — see
 * Admin\IdentityDocumentController, which streams them through an
 * authenticated route instead of exposing the storage path.
 */
class KycService
{
    private const DISK = 'local';

    /**
     * @throws KycConflictException
     * @throws ProfileIncompleteException
     */
    public function submit(
        User $user,
        UploadedFile $frontImage,
        UploadedFile $selfieImage,
        ?string $issuingCountry,
    ): IdentityDocument {
        if ($user->isKycVerified()) {
            throw KycConflictException::alreadyVerified();
        }

        if (! $user->hasCompleteProfile()) {
            throw new ProfileIncompleteException($user->missingProfileFields());
        }

        $hasPending = IdentityDocument::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'under_review'])
            ->exists();

        if ($hasPending) {
            throw KycConflictException::reviewPending();
        }

        $tenantId = Tenant::where('slug', 'default')->value('id');

        $directory = "kyc/{$user->id}";
        $frontPath = Storage::disk(self::DISK)->putFile($directory, $frontImage);
        $selfiePath = Storage::disk(self::DISK)->putFile($directory, $selfieImage);

        return IdentityDocument::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            // Copied from the profile, which the completeness check above
            // guarantees is filled in.
            'type' => $user->document_type,
            'document_number' => $user->document_number,
            'issuing_country' => $issuingCountry,
            'front_path' => $frontPath,
            'selfie_path' => $selfiePath,
            'status' => 'pending',
        ]);
    }

    public function approve(IdentityDocument $document, User $reviewer): IdentityDocument
    {
        $document->forceFill([
            'status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ])->save();

        return $document;
    }

    public function reject(IdentityDocument $document, User $reviewer, string $reason): IdentityDocument
    {
        $document->forceFill([
            'status' => 'rejected',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ])->save();

        return $document;
    }

    public function readImage(IdentityDocument $document, string $which): array
    {
        $path = match ($which) {
            'front' => $document->front_path,
            'selfie' => $document->selfie_path,
            'back' => $document->back_path,
            default => null,
        };

        abort_unless($path, 404);

        $disk = Storage::disk(self::DISK);

        abort_unless($disk->exists($path), 404);

        return [
            'contents' => $disk->get($path),
            'mime' => $disk->mimeType($path) ?: 'application/octet-stream',
        ];
    }
}
