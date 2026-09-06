<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * User-facing: status only, never a path or URL to the underlying file.
 */
class IdentityDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'submitted_at' => $this->created_at,
            'reviewed_at' => $this->reviewed_at,
        ];
    }
}
