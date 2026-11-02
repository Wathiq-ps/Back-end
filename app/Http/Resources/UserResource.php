<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'phone' => $this->phone,
            'name' => $this->name,
            'nationality' => $this->nationality,
            'document_type' => $this->document_type,
            'document_number' => $this->document_number,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'status' => $this->status,
            // Only meaningful for a lawyer account: false means every
            // transacting route will 403 until an admin approves the licence.
            'lawyer_verified' => $this->when($this->isLawyer(), fn () => $this->isVerifiedLawyer()),
            'locale' => $this->locale,
            'email_verified_at' => $this->email_verified_at,
            'phone_verified_at' => $this->phone_verified_at,
            'role' => $this->resolveRole(),
            // The signature file itself is private; the client only needs to
            // know whether it still has to be uploaded.
            'has_signature' => filled($this->signature_path),
            'profile_image_url' => $this->profile_image_path
                ? Storage::disk('public')->url($this->profile_image_path)
                : null,
            'profile_complete' => $this->hasCompleteProfile(),
            'missing_profile_fields' => $this->missingProfileFields(),
            // UC-040 step 2, where the client sends the user once the profile
            // is complete: not_submitted, pending, under_review, approved or
            // rejected. Only approved passes kyc.verified.
            ...$this->identity(),
        ];
    }

    /**
     * An approved document outranks a newer one, so identity_status always
     * agrees with isKycVerified(); otherwise the latest submission decides.
     */
    private function identity(): array
    {
        $document = $this->identityDocuments()
            ->orderByRaw("status = 'approved' desc")
            ->orderByDesc('created_at')
            ->first(['status', 'rejection_reason']);

        return [
            'identity_status' => $document?->status ?? 'not_submitted',
            'identity_rejection_reason' => $document?->status === 'rejected' ? $document->rejection_reason : null,
        ];
    }

    /**
     * A user can hold more than one active membership row — `admin` and
     * `lawyer` are both granted on top of the base `user` role, never in
     * place of it — so "what is this user" needs a fixed precedence rather
     * than whichever row the query happened to return first.
     */
    private function resolveRole(): ?string
    {
        $codes = $this->tenantMemberships()
            ->where('status', 'active')
            ->with('role:id,code')
            ->get()
            ->pluck('role.code');

        foreach (['admin', 'lawyer'] as $privileged) {
            if ($codes->contains($privileged)) {
                return $privileged;
            }
        }

        return $codes->first();
    }
}
