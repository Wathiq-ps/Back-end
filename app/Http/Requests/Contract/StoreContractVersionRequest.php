<?php

namespace App\Http\Requests\Contract;

use Illuminate\Foundation\Http\FormRequest;

class StoreContractVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Only the clauses that changed; the rest carry over.
            'clauses' => ['required', 'array', 'min:1'],
            'clauses.*.ordinal' => ['required', 'integer', 'distinct'],
            'clauses.*.body' => ['required', 'string', 'max:20000'],
            'change_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<int, string> ordinal => body */
    public function edits(): array
    {
        return collect($this->validated('clauses'))
            ->mapWithKeys(fn ($clause) => [(int) $clause['ordinal'] => trim($clause['body'])])
            ->all();
    }
}
