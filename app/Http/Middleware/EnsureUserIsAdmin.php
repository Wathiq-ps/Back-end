<?php

namespace App\Http\Middleware;

use App\Exceptions\Auth\AuthorizationFailedException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after auth.jwt, so $request->user() is already the authenticated
 * user. Admin-ness is an active app.tenant_memberships row with the 'admin'
 * role — see App\Console\Commands\MakeAdmin, the only way to grant it today.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasRole('admin')) {
            throw AuthorizationFailedException::forbidden();
        }

        return $next($request);
    }
}
