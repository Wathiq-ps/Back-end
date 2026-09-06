<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Http\Requests\Auth\RequestOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Services\JwtService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly JwtService $jwt,
    ) {}

    /**
     * UC-037/UC-038: the caller declares 'login' or 'register' up front —
     * OtpService rejects a mismatch (e.g. 'login' for an email that was
     * never registered) before sending a code.
     */
    public function requestOtp(RequestOtpRequest $request): JsonResponse
    {
        $this->otp->requestForEmail(
            $request->validated('email'),
            $request->validated('status'),
            $request->ip(),
        );

        return response()->json([
            'message' => __('A verification code has been sent to your email.'),
            'expires_in_minutes' => (int) config('otp.ttl_minutes'),
        ]);
    }

    /**
     * Verifying the code both authenticates and (on first success) verifies
     * the email, then issues a fresh access + refresh token pair.
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $user = $this->otp->verifyForEmail($request->validated('email'), $request->validated('code'));

        $accessToken = $this->jwt->issueAccessToken($user);
        $refresh = $this->jwt->issueRefreshToken($user, $request->ip(), $request->userAgent());

        return response()->json([
            'user' => new UserResource($user),
            'access_token' => $accessToken,
            'refresh_token' => $refresh['token'],
            'token_type' => 'Bearer',
            'expires_in' => (int) config('jwt.access_ttl'),
        ]);
    }

    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $result = $this->jwt->rotateRefreshToken(
            $request->validated('refresh_token'),
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json([
            'user' => new UserResource($result['user']),
            'access_token' => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'token_type' => 'Bearer',
            'expires_in' => (int) config('jwt.access_ttl'),
        ]);
    }

    public function logout(RefreshTokenRequest $request): JsonResponse
    {
        $this->jwt->revokeRefreshToken($request->validated('refresh_token'));

        return response()->json(['message' => __('Signed out.')]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => new UserResource($request->user())]);
    }
}
