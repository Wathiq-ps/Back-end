<?php

return [
    'code_length' => (int) env('OTP_CODE_LENGTH', 6),
    'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 5),
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
    'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60),
    // How long an account is locked after exhausting attempts on a code.
    'lockout_minutes' => (int) env('OTP_LOCKOUT_MINUTES', 15),
];
