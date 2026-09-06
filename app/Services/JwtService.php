<?php

namespace App\Services;

use App\Exceptions\Auth\AuthenticationFailedException;
use App\Models\User;
use App\Models\UserSession;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use UnexpectedValueException;

/**
 * Access tokens: stateless signed JWTs, never persisted (NFR-4.1). Refresh
 * tokens: opaque random strings, only their SHA-256 hash is persisted in
 * app.user_sessions, so a database dump can't be replayed against the API.
 */
class JwtService
{
    public function issueAccessToken(User $user): string
    {
        $now = time();
        $ttl = (int) config('jwt.access_ttl');

        $payload = [
            'iss' => config('jwt.issuer'),
            'sub' => $user->id,
            'iat' => $now,
            'exp' => $now + $ttl,
        ];

        return JWT::encode($payload, $this->secret(), 'HS256');
    }

    /**
     * @throws AuthenticationFailedException
     */
    public function parseAccessToken(string $token): stdClass
    {
        try {
            return JWT::decode($token, new Key($this->secret(), 'HS256'));
        } catch (ExpiredException|SignatureInvalidException|UnexpectedValueException) {
            throw AuthenticationFailedException::unauthenticated();
        }
    }

    /**
     * Starts a brand new session family (a fresh login).
     *
     * @return array{token: string, session: UserSession}
     */
    public function issueRefreshToken(User $user, ?string $ip, ?string $userAgent): array
    {
        $rawToken = Str::random(64);

        $session = UserSession::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'session_family_id' => (string) Str::uuid(),
            'token_hash' => hash('sha256', $rawToken),
            'device_name' => null,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'issued_at' => now(),
            'expires_at' => now()->addSeconds((int) config('jwt.refresh_ttl')),
        ]);

        return ['token' => $rawToken, 'session' => $session];
    }

    /**
     * Rotates a refresh token: the presented token is revoked and a
     * successor is minted in the same family. Presenting a token that was
     * already rotated away means it leaked — the whole family is revoked.
     *
     * @return array{access_token: string, refresh_token: string, user: User}
     *
     * @throws AuthenticationFailedException
     */
    public function rotateRefreshToken(string $rawToken, ?string $ip, ?string $userAgent): array
    {
        $hash = hash('sha256', $rawToken);

        /** @var UserSession|null $session */
        $session = UserSession::where('token_hash', $hash)->first();

        if (! $session) {
            throw AuthenticationFailedException::invalidRefreshToken();
        }

        if ($session->replaced_by_id !== null || $session->revoked_at !== null) {
            // Reuse of an already-rotated (or already-revoked) token: the
            // refresh token leaked. Kill the whole family, not just this row.
            UserSession::where('session_family_id', $session->session_family_id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revocation_reason' => 'reuse_detected']);

            throw AuthenticationFailedException::invalidRefreshToken();
        }

        if ($session->expires_at->isPast()) {
            throw AuthenticationFailedException::invalidRefreshToken();
        }

        /** @var User|null $user */
        $user = User::find($session->user_id);

        if (! $user) {
            throw AuthenticationFailedException::invalidRefreshToken();
        }

        $newRawToken = Str::random(64);

        return DB::transaction(function () use ($session, $user, $newRawToken, $ip, $userAgent) {
            $newSession = UserSession::create([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'session_family_id' => $session->session_family_id,
                'token_hash' => hash('sha256', $newRawToken),
                'device_name' => $session->device_name,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'issued_at' => now(),
                'expires_at' => now()->addSeconds((int) config('jwt.refresh_ttl')),
            ]);

            $session->update([
                'replaced_by_id' => $newSession->id,
                'revoked_at' => now(),
                'revocation_reason' => 'rotated',
            ]);

            return [
                'access_token' => $this->issueAccessToken($user),
                'refresh_token' => $newRawToken,
                'user' => $user,
            ];
        });
    }

    public function revokeRefreshToken(string $rawToken): void
    {
        UserSession::where('token_hash', hash('sha256', $rawToken))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revocation_reason' => 'logout']);
    }

    private function secret(): string
    {
        $secret = config('jwt.secret') ?: config('app.key');

        return (string) $secret;
    }
}
