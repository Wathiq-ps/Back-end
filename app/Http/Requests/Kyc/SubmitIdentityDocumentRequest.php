<?php

namespace App\Http\Requests\Kyc;

use Illuminate\Foundation\Http\FormRequest;

class SubmitIdentityDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the images are uploaded here. The document type and number are
     * read from the user's profile — KycService::submit rejects the
     * submission with `profile_incomplete` if they were never filled in.
     */
    public function rules(): array
    {
        return [
            'issuing_country' => ['nullable', 'string', 'max:100'],
            'front_image' => ['required', 'image', 'max:8192'],
            'selfie_image' => ['required', 'image', 'max:8192'],
        ];
    }
}
