<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'locale' => $this->locale,
            'email_verified_at' => $this->email_verified_at,
            'phone_verified_at' => $this->phone_verified_at,
            'role' => $this->resolveRole(),
            // The signature file itself is private; the client only needs to
            // know whether it still has to be uploaded.
            'has_signature' => filled($this->signature_path),
            'profile_complete' => $this->hasCompleteProfile(),
            'missing_profile_fields' => $this->missingProfileFields(),
        ];
    }

    /**
     * A user can hold more than one active membership row (e.g. `admin` is
     * granted on top of the base `user` role, never in place of it), but
     * with only two role codes in play there's always one meaningful
     * answer to "what is this user" — prefer `admin` when present.
     */
    private function resolveRole(): ?string
    {
        $codes = $this->tenantMemberships()
            ->where('status', 'active')
            ->with('role:id,code')
            ->get()
            ->pluck('role.code');

        return $codes->contains('admin') ? 'admin' : $codes->first();
    }
}
