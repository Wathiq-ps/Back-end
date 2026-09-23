<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-facing: document_url points at an authenticated streaming route,
 * never at the raw storage path — see Admin\LawyerCredentialController::document().
 */
class LawyerCredentialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'email' => $this->user?->email,
                'phone' => $this->user?->phone,
            ],
            'license_number' => $this->license_number,
            'bar_association' => $this->bar_association,
            'issued_at' => $this->issued_at,
            'expires_at' => $this->expires_at,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'submitted_at' => $this->created_at,
            'reviewed_at' => $this->reviewed_at,
            'reviewer' => $this->reviewed_by ? [
                'id' => $this->reviewer?->id,
                'email' => $this->reviewer?->email,
            ] : null,
            'document_url' => route('admin.lawyers.document', ['lawyerCredential' => $this->user_id]),
        ];
    }
}
