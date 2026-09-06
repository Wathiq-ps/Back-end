<?php

namespace App\Services;

use App\Exceptions\Auth\AuthenticationFailedException;
use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The only credential in this system: a short numeric code sent to the
 * user's email (WhatsApp reserved for later — see app.otp_channel).
 * The caller declares intent via `status` ('login' or 'register') and
 * that intent must match reality — 'login' for an unknown email or
 * 'register' for a known one is rejected before a code is ever sent.
 */
class OtpService
{
    private const CHANNEL = 'email';

    /**
     * Every self-registered user starts with this single role in the MVP's
     * one tenant — there is no admin/owner/beneficiary distinction to
     * choose at sign-up (see UC-037/UC-038 note above). Admin is never
     * granted here; it stays a manual grant via `user:make-admin`.
     */
    private const DEFAULT_ROLE = 'user';

    /**
     * @throws AuthenticationFailedException
     */
    public function requestForEmail(string $email, string $status, ?string $ip): User
    {
        $email = mb_strtolower(trim($email));

        /** @var User|null $existingUser */
        $existingUser = User::where('email', $email)->first();

        if ($status === 'login' && ! $existingUser) {
            throw AuthenticationFailedException::emailNotRegistered();
        }

        if ($status === 'register' && $existingUser) {
            throw AuthenticationFailedException::emailAlreadyRegistered();
        }

        $this->assertNotRateLimited($email, $ip);

        if ($existingUser) {
            $user = $existingUser;
        } else {
            /** @var User $user */
            $user = User::create([
                'email' => $email,
                'status' => 'pending_verification',
                'locale' => app()->getLocale() === 'ar' ? 'ar' : 'en',
            ]);

            $this->grantDefaultRole($user);
        }

        $this->assertNotLocked($user);

        $code = $this->generateCode();

        // At most one live code per user/channel (enforced by a partial
        // unique index too) — consume whatever was pending before issuing a
        // new one, so an old, still-guessable code can't linger.
        OtpCode::where('user_id', $user->id)
            ->where('channel', self::CHANNEL)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        OtpCode::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'channel' => self::CHANNEL,
            'destination' => $email,
            'code_hash' => $this->hash($code),
            'expires_at' => now()->addMinutes((int) config('otp.ttl_minutes')),
            'attempts' => 0,
            'max_attempts' => (int) config('otp.max_attempts'),
            'request_ip' => $ip,
        ]);

        Mail::to($email)->send(new OtpCodeMail($code, (int) config('otp.ttl_minutes')));

        $this->markRequested($email, $ip);

        return $user;
    }

    /**
     * @throws AuthenticationFailedException
     */
    public function verifyForEmail(string $email, string $code): User
    {
        $email = mb_strtolower(trim($email));

        /** @var User|null $user */
        $user = User::where('email', $email)->first();

        if (! $user) {
            throw AuthenticationFailedException::noActiveCode();
        }

        $this->assertNotLocked($user);

        /** @var OtpCode|null $otp */
        $otp = OtpCode::where('user_id', $user->id)
            ->where('channel', self::CHANNEL)
            ->whereNull('consumed_at')
            ->orderByDesc('created_at')
            ->first();

        if (! $otp || $otp->expires_at->isPast()) {
            throw AuthenticationFailedException::noActiveCode();
        }

        if (! hash_equals($otp->code_hash, $this->hash($code))) {
            $otp->increment('attempts');

            if ($otp->attempts >= $otp->max_attempts) {
                $otp->update(['consumed_at' => now()]);
                $this->lockUser($user);
            }

            throw AuthenticationFailedException::invalidCode();
        }

        $otp->update(['consumed_at' => now()]);

        $isFirstVerification = $user->status === 'pending_verification';

        $user->forceFill([
            'failed_login_count' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'email_verified_at' => $user->email_verified_at ?? now(),
            'status' => $isFirstVerification ? 'active' : $user->status,
        ])->save();

        // Transient, not persisted: lets callers tell a first-time
        // verification (registration completing) apart from a login.
        $user->isFirstVerification = $isFirstVerification;

        return $user;
    }

    private function grantDefaultRole(User $user): void
    {
        $tenant = Tenant::where('slug', 'default')->first();

        if (! $tenant) {
            report(new \RuntimeException('No "default" tenant found; cannot grant default role to new user '.$user->id));

            return;
        }

        $role = Role::where('code', self::DEFAULT_ROLE)->first();

        if (! $role) {
            report(new \RuntimeException('No "'.self::DEFAULT_ROLE.'" role found; cannot grant it to new user '.$user->id));

            return;
        }

        TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
    }

    private function generateCode(): string
    {
        $length = (int) config('otp.code_length');

        return (string) random_int(
            (int) str_pad('1', $length, '0'),
            (int) str_pad('', $length, '9'),
        );
    }

    private function hash(string $code): string
    {
        return hash('sha256', $code);
    }

    private function lockUser(User $user): void
    {
        $user->forceFill([
            'failed_login_count' => $user->failed_login_count + 1,
            'locked_until' => now()->addMinutes((int) config('otp.lockout_minutes')),
        ])->save();
    }

    /**
     * @throws AuthenticationFailedException
     */
    private function assertNotLocked(User $user): void
    {
        if ($user->locked_until && $user->locked_until->isFuture()) {
            throw AuthenticationFailedException::accountLocked(
                now()->diffInSeconds($user->locked_until),
            );
        }
    }

    /**
     * @throws AuthenticationFailedException
     */
    private function assertNotRateLimited(string $email, ?string $ip): void
    {
        $cooldown = (int) config('otp.resend_cooldown_seconds');
        $key = $this->throttleKey($email, $ip);
        $lastRequestedAtTimestamp = Cache::get($key);

        if ($lastRequestedAtTimestamp !== null) {
            $retryAfter = $cooldown - (now()->getTimestamp() - (int) $lastRequestedAtTimestamp);

            if ($retryAfter > 0) {
                throw AuthenticationFailedException::rateLimited($retryAfter);
            }
        }
    }

    private function markRequested(string $email, ?string $ip): void
    {
        $cooldown = (int) config('otp.resend_cooldown_seconds');
        // A plain int, not a Carbon instance: some cache stores mangle
        // objects on unserialize if the class isn't autoloaded yet at that
        // point in the request lifecycle.
        Cache::put($this->throttleKey($email, $ip), now()->getTimestamp(), $cooldown);
    }

    private function throttleKey(string $email, ?string $ip): string
    {
        return 'otp:resend:'.hash('sha256', $email);
    }
}
