# DirectAdmin plugin

Provisions hosting accounts on DirectAdmin. Enable it under **Plugins**, add a server (Servers → New, module *DirectAdmin*), then pick the module on a product.

**What it does:** creates a user when the invoice is paid, suspends and unsuspends it, changes its package, deletes it on termination, and gives customers one-click **panel login** and **password reset** buttons.

## Credentials

Use a reseller (or admin) account. Set **Username** to its login and **Secret** to its password or, better, a **Login Key** (Login Keys page). Requests use HTTP Basic auth on port 2222 (the documented recommended method). A Login Key must allow these commands: `CMD_API_ACCOUNT_USER`, `CMD_API_SELECT_USERS`, `CMD_API_MODIFY_USER`, `CMD_API_PACKAGES_USER`, `CMD_API_SHOW_RESELLER_IPS`, `CMD_API_USER_PASSWD`. Do not restrict it by IP unless this server's address is allowed.

**Panel login button:** it creates a one-time login URL with `CMD_API_LOGIN_KEYS` (`action=create&type=one_time_url`), authenticated as `reseller|username` (DirectAdmin's documented impersonation: a reseller may impersonate the users it manages). DirectAdmin also requires the caller's current password in `passwd`, so this works only when the server Secret is the reseller *password*. With a Login Key the button falls back to opening the panel's normal login page (and the Login Key would also need `CMD_API_LOGIN_KEYS`).

## Server fields

Hostname, port, username, secret, SSL options, optional shared IP, optional nameservers 1 and 2, and **Max username length** (see below).

## Product fields

- **User package** (required): exact name of a user package owned by the reseller.
- **IP address**: shared (the server's IP setting, else the reseller's first IP) or dedicated (the next free IP).

The service label must be the account's domain. The username is derived from it: lowercase letters/digits, starting with a letter, up to 10 characters by default (a digit is appended on collisions). DirectAdmin's rule is "start with a-z, >= 3 and <= 10 characters"; `max_username_length` in `directadmin.conf` can be 8 to 14, so set the **Max username length** server field to match if your server changed it.

## Documentation basis

Checked against docs.directadmin.com: Developer > API (overview, authentication, impersonation, `?json=yes`), Legacy API (`CMD_API_ACCOUNT_USER`, `CMD_API_SELECT_USERS`, `CMD_API_SHOW_RESELLER_IPS`, `CMD_API_PACKAGES_USER`), changelogs 1.20.0 (username length), 1.31.0 (`dosuspend`/`dounsuspend`) and 1.58.2 (login URLs). Behaviour:

- Errors: `CMD_API_ACCOUNT_*` answer `error=0|1&text=...&details=...`; `error=1` becomes the failure message. Success of `CMD_API_SELECT_USERS` and `CMD_API_MODIFY_USER` is a 302 redirect, which is not followed and counts as success. List replies (`list[]=`) are HTML-entity-decoded then parsed.
- Create: `action=create&add=Submit&username&email&passwd&passwd2&domain&package&ip&notify=no`.
- Suspend/unsuspend: `dosuspend` / `dounsuspend` (non-toggling; the older `suspend=Suspend|Unsuspend` toggles).
- Delete: `confirmed=Confirm&delete=yes&select0=<user>`.

**Not in the official reference (taken from DirectAdmin staff and forum posts, verify on your server):**

- Package change: `CMD_API_MODIFY_USER` with `action=package&user&package`. The reference documents only `action=customize` for this command.
- Password reset: `CMD_API_USER_PASSWD` is not documented. If the account password is stored, we call it as the account with `oldpassword`; otherwise as the reseller with `username`, `passwd`, `passwd2` (the form reported working for admins; whether a reseller may do this is unconfirmed). The command is reported to return empty or unreliable bodies, so success means "no error returned".
- One-time URL via `CMD_API_LOGIN_KEYS`: parameters come from a DirectAdmin forum thread; the docs only describe the root-only `da login-url`.
