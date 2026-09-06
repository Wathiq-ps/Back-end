<?php

namespace App\Exceptions\Auth;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The caller is authenticated but not allowed to do this — distinct from
 * AuthenticationFailedException, which covers "who even are you" failures.
 */
class AuthorizationFailedException extends Exception
{
    private function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function forbidden(): self
    {
        return new self('You are not allowed to perform this action.', 'forbidden', 403);
    }

    /**
     * UC-040 gate: browsing stays open to everyone, but renting/buying/
     * signing requires an approved identity document.
     */
    public static function kycRequired(): self
    {
        return new self(
            'Your account must be verified before you can do this.',
            'kyc_required',
            403,
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
        ], $this->status);
    }
}
