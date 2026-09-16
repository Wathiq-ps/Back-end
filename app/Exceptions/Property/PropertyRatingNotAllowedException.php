<?php

namespace App\Exceptions\Property;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rating is earned by having lived the transaction, not by being logged in
 * — distinct from AuthorizationFailedException::forbidden(), which covers
 * ownership checks unrelated to contract history.
 */
class PropertyRatingNotAllowedException extends Exception
{
    private function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function noCompletedContract(): self
    {
        return new self(
            'You can only rate a property after a completed contract on it.',
            'rating_requires_completed_contract',
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
        ], 403);
    }
}
