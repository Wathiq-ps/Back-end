<?php

namespace App\Http\Requests\Kyc;

use Illuminate\Foundation\Http\FormRequest;

class SubmitIdentityDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:national_id,passport,residency_permit,commercial_register'],
            'document_number' => ['required', 'string', 'max:64'],
            'issuing_country_id' => ['nullable', 'uuid'],
            'front_image' => ['required', 'image', 'max:8192'],
            'selfie_image' => ['required', 'image', 'max:8192'],
        ];
    }
}
