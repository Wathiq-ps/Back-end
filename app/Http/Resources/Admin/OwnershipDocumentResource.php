<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-facing: file_url points at an authenticated streaming route, never at
 * the raw storage path or a pre-signed URL — see
 * Admin\PropertyController::documentFile().
 *
 * Carries enough of the parent listing for the review queue to be actionable
 * on its own, without the admin having to open the property first.
 */
class OwnershipDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property' => [
                'id' => $this->property?->id,
                'reference' => $this->property?->reference,
                'title' => $this->property?->title,
                'status' => $this->property?->status,
                'city' => $this->property?->city,
                'district' => $this->property?->district,
                'owner' => [
                    'id' => $this->property?->owner?->id,
                    'name' => $this->property?->owner?->name,
                    'email' => $this->property?->owner?->email,
                    'phone' => $this->property?->owner?->phone,
                ],
            ],
            'type' => $this->type,
            'document_number' => $this->document_number,
            'issued_on' => $this->issued_on,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'uploaded_by' => $this->uploaded_by ? [
                'id' => $this->uploader?->id,
                'name' => $this->uploader?->name,
                'email' => $this->uploader?->email,
            ] : null,
            'submitted_at' => $this->created_at,
            'reviewed_at' => $this->reviewed_at,
            'reviewer' => $this->reviewed_by ? [
                'id' => $this->reviewer?->id,
                'email' => $this->reviewer?->email,
            ] : null,
            'file_url' => route('admin.properties.documents.file', ['ownershipDocument' => $this->id]),
        ];
    }
}
