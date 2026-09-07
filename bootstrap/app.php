<?php

use App\Exceptions\Auth\AuthenticationFailedException;
use App\Exceptions\Auth\AuthorizationFailedException;
use App\Exceptions\Kyc\KycConflictException;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Http\Middleware\JwtAuthenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.jwt' => JwtAuthenticate::class,
            'admin' => EnsureUserIsAdmin::class,
            'kyc.verified' => EnsureUserIsVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // These three all carry their own render() and a stable error_code —
        // they are expected 4xx outcomes (wrong OTP, email already registered,
        // KYC state conflict), not faults. Reporting them wrote a ~43-line
        // stack trace per occurrence into production logs, which buried the
        // failures that do matter.
        $exceptions->dontReport([
            AuthenticationFailedException::class,
            AuthorizationFailedException::class,
            KycConflictException::class,
        ]);
    })->create();
