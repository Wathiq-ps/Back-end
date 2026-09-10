<?php

namespace App\Exceptions\Property;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PropertyConflictException extends Exception
{
    private function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function duplicatePendingSubmission(): self
    {
        return new self(
            'You already submitted this property and it is still awaiting review. Please wait for it to be reviewed before submitting it again.',
            'property_duplicate_pending',
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
