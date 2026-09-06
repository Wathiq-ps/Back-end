<?php

namespace App\Http\Middleware;

use App\Exceptions\Auth\AuthorizationFailedException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * UC-040: gates renting, buying, and any other transacting action behind an
 * approved identity document. Browsing/search routes must NOT get this
 * middleware — only attach it to the request/contract-creation routes once
 * those exist. Not attached to any route yet.
 */
class EnsureUserIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isKycVerified()) {
            throw AuthorizationFailedException::kycRequired();
        }

        return $next($request);
    }
}
