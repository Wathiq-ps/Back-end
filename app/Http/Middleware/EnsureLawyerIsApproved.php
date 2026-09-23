<?php

namespace App\Http\Middleware;

use App\Exceptions\Auth\AuthorizationFailedException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-13.5. A lawyer-type account can do nothing until an admin approves its
 * licence. A no-op for ordinary users, so it is safe — and intended — to sit
 * next to kyc.verified on every transacting route.
 *
 * Deliberately NOT applied to /v1/profile, /v1/kyc or /v1/lawyer: those are
 * the routes an unapproved lawyer needs in order to *become* approved, and
 * gating them would deadlock the account.
 */
class EnsureLawyerIsApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isLawyer() && ! $user->isVerifiedLawyer()) {
            throw AuthorizationFailedException::lawyerApprovalRequired();
        }

        return $next($request);
    }
}
