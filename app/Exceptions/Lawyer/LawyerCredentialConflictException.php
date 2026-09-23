<?php

namespace App\Exceptions\Lawyer;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LawyerCredentialConflictException extends Exception
{
    private function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function alreadyVerified(): self
    {
        return new self('This account is already verified as a lawyer.', 'lawyer_already_verified');
    }

    public static function reviewPending(): self
    {
        return new self(
            'Your credentials are already awaiting review. Please wait for them to be reviewed before submitting again.',
            'lawyer_review_pending',
        );
    }

    public static function licenseTaken(): self
    {
        return new self(
            'That licence number is already registered to another account.',
            'lawyer_license_taken',
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
        ], 409);
    }
}
