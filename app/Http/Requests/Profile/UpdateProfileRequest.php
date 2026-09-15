<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Every field is `sometimes`: the profile screen can be filled in over
     * several saves. Completeness is enforced at the point it matters — the
     * KYC submission — not here.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:191'],
            'nationality' => ['sometimes', 'string', 'max:100'],
            'signature_image' => ['sometimes', 'image', 'max:8192'],
            'document_type' => ['sometimes', 'string', 'max:100'],
            'document_number' => ['sometimes', 'string', 'max:100'],
            'date_of_birth' => ['sometimes', 'date'],
            'email' => ['sometimes', 'email', 'max:191', 'unique:users,email,'.$this->user()->id],
            // E.164, mirroring the app.phone domain — caught here as a clean
            // 422 instead of a constraint-violation 500.
            'phone_number' => ['sometimes', 'string', 'max:20', 'regex:/^\+[1-9][0-9]{7,14}$/'],
        ];
    }
}
