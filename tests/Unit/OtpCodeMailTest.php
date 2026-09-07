<?php

use App\Mail\OtpCodeMail;
use Illuminate\Contracts\Queue\ShouldQueue;

// Regression guard. Sending this mail inline blocks the request on the SMTP
// socket; in production that hit PHP's 30s limit and fataled mid-socket,
// leaving a committed-but-undelivered OTP row behind. Dropping ShouldQueue
// silently restores exactly that failure, so pin it here.
test('otp mail is queued, never sent inline', function () {
    expect(new OtpCodeMail('123456', 5))->toBeInstanceOf(ShouldQueue::class);
});
