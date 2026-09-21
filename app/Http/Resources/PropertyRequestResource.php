<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PropertyRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'type' => $this->type,
            'term_start' => $this->term_start,
            'term_end' => $this->term_end,
            'message' => $this->message,
            'status' => $this->status,
            'response_note' => $this->response_note,
            'responded_at' => $this->responded_at,
            'property' => [
                'id' => $this->property?->id ?? $this->property_id,
                'reference' => $this->property?->reference,
                'title' => $this->property?->title,
            ],
            'lawyer' => $this->lawyer_id ? [
                'id' => $this->lawyer?->id,
                'name' => $this->lawyer?->name,
            ] : null,
            'created_at' => $this->created_at,
        ];
    }
}
