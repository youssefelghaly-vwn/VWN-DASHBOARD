# Team invitations

Admins invite people by email; the invitee clicks a link in that email and sets
their own password the first time, which activates the account and logs them in.
No admin ever sets or sees another user's password.

## Flow

```
Admin → /team (Team page)
  └─ POST /team/invite {email, name?, is_admin?}   TeamController@invite
       ├─ create (or reuse a still-pending) User with password = NULL
       ├─ User@issueInvitationToken()  → stores sha256(token) + invited_at, returns plaintext
       └─ Mail::to(email)->send(UserInvitationMail)   // link = /invite/{plaintextToken}

Invitee ← email "Set your password"
  └─ GET  /invite/{token}    InvitationController@show   → auth.accept-invitation (or invalid)
  └─ POST /invite/{token}    InvitationController@store  {name, password, password_confirmation}
       ├─ User@acceptInvitation(password): set hashed password, email_verified_at=now, token=NULL
       ├─ event(Registered)
       └─ Auth::login() + session regenerate → redirect to dashboard
```

## Security

- **Token** is 64 random chars; only its **SHA-256 hash** is stored. The plaintext
  lives only in the emailed link, so a database leak can't reveal usable links.
- **Single-use**: the token is cleared the moment the invite is accepted, so the
  link can't be replayed.
- **Expiry**: links are valid for `User::INVITE_EXPIRES_DAYS` (14) days from
  `invited_at`; expired or unknown tokens render a neutral "invitation invalid"
  page (no account enumeration).
- **Pending vs active**: a user is *pending* while `password IS NULL`. Inviting an
  already-active email is rejected; inviting a still-pending email just re-sends a
  fresh link (same as **Resend**). Password-less users can't log in via the normal
  form until they accept.
- Invite/list/resend/remove all live behind `auth` + `admin`. Admins can't remove
  their own account.

## Data model

`users` gains: `invitation_token` (hashed, nullable), `invited_at`, `invited_by`
(FK → users), and `password` is now **nullable**. `is_admin` is cast to boolean.

## Files

| File | Role |
|------|------|
| `app/Team/Controllers/TeamController.php` | Admin: list / invite / resend / remove. |
| `app/Team/Controllers/InvitationController.php` | Guest: show accept page / set password. |
| `app/Team/Mail/UserInvitationMail.php` + `resources/views/emails/invitation.blade.php` | The invite email. |
| `resources/views/admin/team.blade.php` | Team management page (sidebar "Team"). |
| `resources/views/auth/accept-invitation.blade.php` / `auth/invitation-invalid.blade.php` | Invitee pages. |
| `app/Models/User.php` | Token issue/verify/accept helpers. |
| `database/migrations/*_add_invitation_fields_to_users.php` | Schema. |

## Email delivery

Uses Laravel's mailer. In local/dev `MAIL_MAILER=log`, so invite emails are
written to `storage/logs/laravel.log` (open the link from there to test). Set the
real SMTP env vars (`MAIL_MAILER`, `MAIL_HOST`, …) in production to actually send.
`APP_URL` must be correct so the emailed link points at the right host.
