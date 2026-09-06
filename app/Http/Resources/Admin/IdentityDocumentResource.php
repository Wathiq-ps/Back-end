<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-facing: image_urls point at an authenticated streaming route, never
 * at the raw storage path — see Admin\IdentityDocumentController::image().
 */
class IdentityDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => [
                'id' => $this->user?->id,
                'email' => $this->user?->email,
                'phone' => $this->user?->phone,
            ],
            'type' => $this->type,
            'document_number' => $this->document_number,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'submitted_at' => $this->created_at,
            'reviewed_at' => $this->reviewed_at,
            'reviewer' => $this->reviewed_by ? [
                'id' => $this->reviewer?->id,
                'email' => $this->reviewer?->email,
            ] : null,
            'image_urls' => [
                'front' => route('admin.kyc.image', ['identityDocument' => $this->id, 'which' => 'front']),
                'selfie' => route('admin.kyc.image', ['identityDocument' => $this->id, 'which' => 'selfie']),
                'back' => $this->back_path
                    ? route('admin.kyc.image', ['identityDocument' => $this->id, 'which' => 'back'])
                    : null,
            ],
        ];
    }
}
