<?php

namespace App\Exceptions\Property;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Distinct from AuthorizationFailedException::forbidden() — this is a
 * property-request-specific business rule (also enforced by a DB trigger,
 * app.assert_requester_is_not_owner), not a generic permission check.
 */
class PropertyRequestNotAllowedException extends Exception
{
    private function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function cannotRequestOwnProperty(): self
    {
        return new self(
            'You cannot submit a request against your own property.',
            'property_request_own_property',
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
