<?php

namespace App\Http\Requests\Property;

use Illuminate\Foundation\Http\FormRequest;

class AcceptPropertyRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only structural validation here (a lawyer_id was sent and points at
     * an existing user) — whether that user is actually a verified lawyer
     * is a domain rule, checked in PropertyRequestService::accept().
     */
    public function rules(): array
    {
        return [
            'lawyer_id' => ['required', 'uuid', 'exists:users,id'],
        ];
    }
}
