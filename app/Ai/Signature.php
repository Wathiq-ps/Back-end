<?php

namespace App\Ai;

use RuntimeException;

/**
 * X-Wathiq-Signature for the job requests this app sends the AI service:
 * t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")> — the scheme the AI
 * already signs its callbacks with (AiCallbackController checks those), over
 * the same shared AI_WEBHOOK_SECRET. The AI refuses an unsigned job request:
 * without this, anyone who could reach it could post their own terms under a
 * real job id and have the AI sign the result (Wathiq-ps/Ai app/security.py).
 */
final class Signature
{
    public static function header(string $raw, ?int $timestamp = null): string
    {
        $secret = (string) config('services.ai.webhook_secret');
        if ($secret === '') {
            // Never send a job unsigned: the AI would refuse it anyway, and a
            // missing secret is a deploy mistake worth failing loudly on.
            throw new RuntimeException('AI webhook secret is not configured.');
        }

        $t = $timestamp ?? time();

        return 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$raw, $secret);
    }
}
