<?php

namespace App\Http\Middleware;

use App\Exceptions\Auth\AuthenticationFailedException;
use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JwtAuthenticate
{
    public function __construct(private readonly JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            throw AuthenticationFailedException::unauthenticated();
        }

        $payload = $this->jwt->parseAccessToken($token);

        $user = User::find($payload->sub);

        if (! $user) {
            throw AuthenticationFailedException::unauthenticated();
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
