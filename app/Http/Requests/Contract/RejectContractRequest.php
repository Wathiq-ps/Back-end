<?php

namespace App\Http\Requests\Contract;

use Illuminate\Foundation\Http\FormRequest;

class RejectContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // It goes back to the lawyer, so it must say what to change.
            'note' => ['required', 'string', 'max:1000'],
        ];
    }
}
