<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your invitation</title>
</head>
<body style="margin:0;padding:0;background:#F6F3EA;font-family:'Helvetica Neue',Arial,sans-serif;color:#12241F;">
    <div style="max-width:520px;margin:0 auto;padding:32px 16px;">
        <div style="background:#0E211D;border-radius:16px 16px 0 0;padding:28px 32px;">
            <div style="color:#4FE3A6;font-size:13px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;">
                {{ config('app.name') }}
            </div>
        </div>

        <div style="background:#FFFFFF;border:1px solid #DED6C0;border-top:0;border-radius:0 0 16px 16px;padding:32px;">
            <h1 style="margin:0 0 12px;font-size:22px;">You’ve been invited 🎉</h1>

            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#5B6B64;">
                @if ($inviterName)
                    <strong style="color:#12241F;">{{ $inviterName }}</strong> has invited you
                @else
                    You’ve been invited
                @endif
                to join <strong style="color:#12241F;">{{ config('app.name') }}</strong> as
                <strong style="color:#12241F;">{{ $user->email }}</strong>.
            </p>

            <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#5B6B64;">
                Click the button below to set your password and activate your account.
            </p>

            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                <tr>
                    <td style="border-radius:10px;background:#1C7A5C;">
                        <a href="{{ $acceptUrl }}"
                           style="display:inline-block;padding:13px 26px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
                            Set your password
                        </a>
                    </td>
                </tr>
            </table>

            <p style="margin:0 0 8px;font-size:12.5px;line-height:1.6;color:#8A968F;">
                This invitation expires in {{ \App\Models\User::INVITE_EXPIRES_DAYS }} days. If you weren’t expecting it, you can ignore this email.
            </p>
            <p style="margin:0;font-size:12px;line-height:1.6;color:#A9B4AE;word-break:break-all;">
                Or paste this link into your browser:<br>{{ $acceptUrl }}
            </p>
        </div>
    </div>
</body>
</html>
