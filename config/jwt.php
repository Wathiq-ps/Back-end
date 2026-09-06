<?php

return [
    /*
     * HS256 signing secret for API access tokens. Independent from APP_KEY
     * so rotating one doesn't invalidate the other (APP_KEY also encrypts
     * cookies/sessions).
     */
    'secret' => env('JWT_SECRET'),

    // Short-lived on purpose: this is the token sent on every request and
    // never stored server-side, so it can't be revoked before it expires.
    'access_ttl' => (int) env('JWT_ACCESS_TTL', 9000000000), // 15 minutes

    // Long-lived but DB-tracked (app.user_sessions), so it can be revoked.
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 60 * 60 * 24 * 30), // 30 days

    'issuer' => env('APP_URL', 'wathiq'),
];
