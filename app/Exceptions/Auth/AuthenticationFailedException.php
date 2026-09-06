<?php

namespace App\Exceptions\Auth;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Any failure along the OTP request/verify/refresh path. Carries a stable
 * machine-readable `code` (for the mobile client to branch on) separate
 * from the human-readable `message`.
 */
class AuthenticationFailedException extends Exception
{
    private function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $status,
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self(
            'Too many OTP requests. Please wait before trying again.',
            'otp_rate_limited',
            429,
            $retryAfterSeconds,
        );
    }

    public static function accountLocked(int $retryAfterSeconds): self
    {
        return new self(
            'This account is temporarily locked due to too many failed attempts.',
            'account_locked',
            423,
            $retryAfterSeconds,
        );
    }

    public static function noActiveCode(): self
    {
        return new self(
            'No active code was found for this address. Please request a new one.',
            'otp_not_found',
            422,
        );
    }

    public static function invalidCode(): self
    {
        return new self(
            'The code you entered is incorrect.',
            'otp_invalid',
            422,
        );
    }

    public static function invalidRefreshToken(): self
    {
        return new self(
            'This session is no longer valid. Please sign in again.',
            'session_invalid',
            401,
        );
    }

    public static function emailNotRegistered(): self
    {
        return new self(
            'This email is not registered. Please register first.',
            'email_not_registered',
            404,
        );
    }

    public static function emailAlreadyRegistered(): self
    {
        return new self(
            'This email is already registered. Please login instead.',
            'email_already_registered',
            409,
        );
    }

    public static function unauthenticated(): self
    {
        return new self(
            'Authentication required.',
            'unauthenticated',
            401,
        );
    }

    public function render(Request $request): JsonResponse
    {
        $payload = [
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
        ];

        if ($this->retryAfter !== null) {
            $payload['retry_after'] = $this->retryAfter;
        }

        $response = response()->json($payload, $this->status);

        if ($this->retryAfter !== null) {
            $response->header('Retry-After', (string) $this->retryAfter);
        }

        return $response;
    }
}
