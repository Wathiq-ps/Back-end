<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Same constraints as `profile_image` in UpdateProfileRequest, but
     * required: this endpoint does nothing else.
     */
    public function rules(): array
    {
        return [
            'profile_image' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:5120'],
        ];
    }
}
