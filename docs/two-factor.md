# Two-factor authentication

Staff and clients can protect their login with any authenticator app (TOTP, 6 digits, 30 seconds).

- **Staff:** account menu → Security (`/admin/account/security`).
- **Clients:** client area → Security (`/portal/security`).

Setting up shows a QR code, the manual key and the `otpauth://` link, and only turns on once a code is confirmed. Ten one-time recovery codes are shown once and stored hashed. Regenerating codes needs your password; turning 2FA off needs your password and a code.

At sign-in, after the password, a code (or a recovery code) is asked for. "Remember this device" skips the prompt for 30 days; it is cancelled automatically when the password changes or 2FA is reset or turned off. Staff viewing a client ("view as client") never need the client's code and cannot change the client's 2FA settings.

## Trusted devices

On the Security page (staff and client area) **Trusted devices** lists every browser that was remembered: a description (for example "Firefox on Windows"), IP address, when it was added, last used and expires. The device you are using is marked "This device". **Revoke** removes one (it asks for a code again at its next sign-in), **Revoke all** removes every device. Revocations are written to the activity log.

How it works: the browser keeps an HttpOnly, encrypted and signed cookie holding only a random token; the server stores just a hash of it (table `two_factor_devices`), so a database leak cannot be turned into a cookie. A device stops being trusted when it expires (expired rows are pruned automatically, or with `php artisan model:prune`), when the password changes, and when 2FA is reset or turned off (all devices are deleted). Cookies from versions before 0.2 used a different format and are no longer accepted: those browsers are asked for a code once and can be remembered again.

## Requiring it for staff

**Admin → Settings → Security → Require two-factor authentication for staff.** While on, staff without 2FA are redirected to set it up before using the admin area.

The requirement covers the API as well. While it is on, **every** API call with the token of a staff user who has not enrolled is refused with `403` `two_factor_enrolment_required`, whether the token already existed, came from the admin screen or admin API, or was issued some other way. The same applies to creating API tokens (admin screen and `POST /admin/api-tokens`). It works again as soon as the owner has turned on 2FA (enrolment itself is browser-only). The one exception is `DELETE /auth/token`: an unenrolled user can still revoke their own token. Customers are not affected.

## Lost phone and recovery codes

An administrator can reset a client login's 2FA from the client page (Contacts tab, with an optional "Revoke API tokens" box). Administrators can reset staff from **Admin → Staff** (or `POST /admin/staff-users/{id}/two-factor/reset`); both use the same rules: only administrators can reset staff, and nobody can reset their own.

**What happens to API tokens?**

| Action | API tokens |
|---|---|
| You turn 2FA off yourself (password and code needed) | kept |
| An administrator resets someone's 2FA | kept, unless "revoke API tokens" is ticked (`revoke_tokens` in the form, `revoke_api_tokens` in the API) |
| `billing:reset-2fa` | kept, unless `--revoke-tokens` is given |
| A staff user is deactivated | always revoked; any signed-in browser session ends on its next request, whatever the session driver |

Tick the option whenever the account may be compromised: a reset only removes the second factor, it does not end other sessions or tokens. Remembered devices are always removed. Resets are written to the activity log with the number of revoked tokens.

## Managing staff

**Admin → Staff** (administrators only) lists staff with role, last sign-in, 2FA status and whether they are active. Administrators can add staff (choose a password or email a link), change a role, deactivate and reactivate, send a password reset link and reset 2FA. Safeguards: the last active administrator can be neither demoted nor deactivated, and nobody can deactivate themselves. The same operations are in the admin API under `/admin/staff-users`, see [`api.md`](api.md).

If the **only administrator** is locked out, run on the server:

```
php artisan billing:reset-2fa admin@example.com
```

Add `--revoke-tokens` to also revoke the account's API tokens.

This turns 2FA off for that account (and logs it in the activity log). Sign in with the password and set it up again. Anyone able to run this has shell access, so it is as trusted as editing the database.

## For developers

`App\Services\TwoFactor::verify(User $user, string $code): bool` accepts a TOTP code or a recovery code, refuses replays (each time step and each recovery code works once) and is safe to reuse for API authentication. Callers must apply their own attempt throttling.
