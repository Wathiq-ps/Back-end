<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RequestOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * role is only meaningful while registering — on login the account
     * already holds one, and letting a caller re-declare it would be a way
     * to change role without review. 'admin' is deliberately not accepted:
     * it stays a manual grant via `user:make-admin`.
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'status' => ['required', 'in:login,register'],
            'role' => ['required_if:status,register', 'prohibited_if:status,login', 'in:user,lawyer'],
        ];
    }
}
