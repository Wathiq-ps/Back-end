<?php

namespace App\Exceptions\Kyc;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UC-040: identity documents can't be reviewed against a profile that has no
 * legal name, nationality or signature on it. `missing` is returned so the
 * client can route the user straight back to the profile screen.
 */
class ProfileIncompleteException extends Exception
{
    /**
     * @param  list<string>  $missing
     */
    public function __construct(private readonly array $missing)
    {
        parent::__construct(
            'Please fill in the missing fields from Edit Profile before uploading your identity documents.'
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => 'profile_incomplete',
            'missing' => $this->missing,
        ], 422);
    }
}
