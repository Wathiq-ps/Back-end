<?php

namespace App\Exceptions\Kyc;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KycConflictException extends Exception
{
    private function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function alreadyVerified(): self
    {
        return new self('This account is already verified.', 'kyc_already_verified');
    }

    public static function reviewPending(): self
    {
        return new self(
            'A document is already awaiting review. Please wait for it to be reviewed before submitting another.',
            'kyc_review_pending',
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
