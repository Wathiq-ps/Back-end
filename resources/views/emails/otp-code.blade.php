<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; background:#f4f4f5; padding:24px;">
    <table role="presentation" width="100%" style="max-width:480px; margin:0 auto; background:#ffffff; border-radius:8px; padding:32px;">
        <tr>
            <td>
                <h1 style="font-size:18px; margin:0 0 16px;">{{ __('Your verification code') }}</h1>
                <p style="color:#52525b; margin:0 0 24px;">
                    {{ __('Use this code to sign in to Wathiq. It expires in :minutes minutes.', ['minutes' => $ttlMinutes]) }}
                </p>
                <div style="font-size:32px; font-weight:700; letter-spacing:8px; text-align:center; padding:16px; background:#f4f4f5; border-radius:6px;">
                    {{ $code }}
                </div>
                <p style="color:#a1a1aa; font-size:12px; margin-top:24px;">
                    {{ __("If you didn't request this code, you can safely ignore this email.") }}
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
