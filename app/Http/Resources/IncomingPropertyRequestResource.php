<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The property owner's view of a request against one of their listings —
 * unlike PropertyRequestResource (the requester's own view), this includes
 * enough about the property and requester for the owner to actually decide.
 */
class IncomingPropertyRequestResource extends JsonResource
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
                'id' => $this->property?->id,
                'reference' => $this->property?->reference,
                'title' => $this->property?->title,
            ],
            'requester' => [
                'id' => $this->requester?->id,
                'name' => $this->requester?->name,
                'email' => $this->requester?->email,
                'phone' => $this->requester?->phone,
            ],
            'lawyer' => $this->lawyer_id ? [
                'id' => $this->lawyer?->id,
                'name' => $this->lawyer?->name,
                'email' => $this->lawyer?->email,
            ] : null,
            'created_at' => $this->created_at,
        ];
    }
}
