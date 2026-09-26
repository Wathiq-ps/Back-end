<?php

namespace App\Http\Requests\Contract;

use Illuminate\Foundation\Http\FormRequest;

class ResolveFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `superseded` is set by a new version, never chosen.
            'resolution' => ['required', 'in:accepted,rejected'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
