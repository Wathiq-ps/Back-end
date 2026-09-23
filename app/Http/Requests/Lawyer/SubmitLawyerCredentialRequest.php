<?php

namespace App\Http\Requests\Lawyer;

use Illuminate\Foundation\Http\FormRequest;

class SubmitLawyerCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * jurisdiction_id isn't asked for — there is one jurisdiction (Palestine)
     * and LawyerCredentialService resolves it, the same way tenant is resolved
     * everywhere else rather than trusting a client-sent UUID.
     */
    public function rules(): array
    {
        return [
            'license_number' => ['required', 'string', 'max:64'],
            'bar_association' => ['required', 'string', 'max:191'],
            'issued_at' => ['required', 'date', 'before_or_equal:today'],
            'expires_at' => ['nullable', 'date', 'after:issued_at'],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:8192'],
        ];
    }
}
