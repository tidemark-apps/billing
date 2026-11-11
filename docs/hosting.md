# Running Tidemark Billing for other people (hosted mode)

For a provider that runs one install per customer. Self-hosters can ignore all of this: with none of these settings, nothing changes.

## Environment

| Variable | Effect |
|---|---|
| `BILLING_HOSTED=true` | Closes `/install` and plugin uploads, fixes the mail transport to the environment (the owner can only set the From name), lets `billing:install` run without a password. |
| `BILLING_ADMIN_EMAIL` | With `BILLING_HOSTED`, the Docker image installs on first start and creates this owner. Run `php artisan billing:login-link <email> --reset` to get a link where the owner chooses a password (valid for `PASSWORD_RESET_EXPIRE` minutes, default 60). |
| `BILLING_PLAN_FILE` | Path of the plan file below. |
| `BILLING_SSO_PUBLIC_KEY` | Base64 Ed25519 public key of the host. Turns on `/sso`. |

## Plan file

```json
{"name": "starter", "limits": {"clients": 100, "staff": 2}, "suspended": false, "suspended_message": "Payment failed."}
```

A missing file, or a missing or null limit, means no limit. At a limit, new clients and new active staff users are refused (a message on the web, a 422 from the API); existing ones keep working. `suspended: true` shows a banner on every admin and portal page. `"whitelabel": true` removes the Tidemark branding: the "Powered by Tidemark Billing" footers and the Tidemark favicon go away, and the admin header shows the company logo or name. `php artisan billing:usage` prints the plan, limits, white label flag and usage as one line of JSON.

## Sign-in links from the host

`GET /sso?token=…` takes a token in the same format as Tidemark Analytics: `base64url(claims) "." base64url(Ed25519 signature of the first part)`, no padding. Claims: `aud` (this install's host), `iat`, `exp`, `jti`, `kind` (`customer` or `support`), optional `email` (default: the first administrator), and for support `staff`, `reason`, `ticket`. A token works once and only for its `aud`. A `customer` token still goes through two-factor authentication. A `support` token does not; it records who, why and which ticket in the activity log and shows a banner for the whole session.

## Container

Run the image with `--read-only --tmpfs /tmp --tmpfs /config:uid=1000,gid=1000 -v data:/data`. In a test with a 384 MB limit it used about 90 MB idle and peaked near 220 MB (file cache included) under parallel invoice and PDF requests.
