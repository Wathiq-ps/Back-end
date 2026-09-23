<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lawyer-facing: status and what they submitted, never the storage path —
 * same posture as IdentityDocumentResource.
 */
class LawyerCredentialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'license_number' => $this->license_number,
            'bar_association' => $this->bar_association,
            'issued_at' => $this->issued_at,
            'expires_at' => $this->expires_at,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'submitted_at' => $this->created_at,
            'reviewed_at' => $this->reviewed_at,
        ];
    }
}
