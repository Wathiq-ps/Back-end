<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The signature image is stored on the same private disk as identity
 * documents — it is evidence that ends up on contracts (app.signatures),
 * so it is never exposed by a public URL.
 */
class ProfileService
{
    private const DISK = 'local';

    private const WRITABLE = [
        'name', 'nationality', 'document_type', 'document_number', 'date_of_birth', 'email',
    ];

    /**
     * The request field is `phone_number`; the column is `phone` (an E.164
     * app.phone domain). Everything else maps one to one.
     */
    public function update(User $user, array $data, ?UploadedFile $signature = null): User
    {
        $attributes = array_intersect_key($data, array_flip(self::WRITABLE));

        if (array_key_exists('phone_number', $data)) {
            $attributes['phone'] = $data['phone_number'];
        }

        // Changing the address means ownership of the new one hasn't been
        // proven yet — the next OTP login to it is what re-verifies.
        if (array_key_exists('email', $attributes) && $attributes['email'] !== $user->email) {
            $attributes['email_verified_at'] = null;
        }

        if ($signature) {
            $previousPath = $user->signature_path;

            $attributes['signature_path'] = Storage::disk(self::DISK)
                ->putFile("signatures/{$user->id}", $signature);

            if ($previousPath) {
                Storage::disk(self::DISK)->delete($previousPath);
            }
        }

        // forceFill: the whitelist above is what guards this, and
        // email_verified_at is deliberately not mass-assignable.
        if ($attributes !== []) {
            $user->forceFill($attributes)->save();
        }

        return $user;
    }
}
