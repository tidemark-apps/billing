# cPanel & WHM plugin

Provisions hosting accounts on cPanel & WHM through the WHM API 1. Enable it under **Plugins**, add a server (Servers → New, module *cPanel & WHM*), then pick the module on a product.

**What it does:** creates an account when the invoice is paid, suspends and unsuspends it, changes its package, deletes it on termination, and gives customers one-click **cPanel login**, **webmail** and **password reset** buttons (single sign-on through `create_user_session`).

## Create the API token

In WHM: **Development → Manage API Tokens → Generate Token**. Name it (e.g. `billing`) and grant these ACLs (or "all" for root):
`create-acct`, `suspend-acct`, `kill-acct`, `list-pkgs`, `edit-account` (package changes), `passwd`, `create-user-session`, and `basic-system-info` (the connection test).

Authentication is `Authorization: whm USER:TOKEN` over HTTPS (port 2087), every call carries `api.version=1`, and failures are read from `metadata.result` / `metadata.reason`. Timeouts: 10 s connect, 30 s total. For a server with a self-signed certificate, turn off SSL verification in the server's settings (or install a real certificate).

**Passwords and logs:** the WHM API 1 reference documents every function (including `createacct` and `passwd`) as GET only, so the plugin sends them as GET query parameters over TLS, exactly as documented. The plugin never logs URLs and strips them from connection errors, but WHM's own access logs on the server will contain the password parameter; restrict access to those logs.

Use the token's owner as the server's **Username** (`root` or a reseller) and the token as the **Secret**. Default port is 2087. If WHM restricts API access by IP, allow this server's address.

## Server fields

Hostname, port, username, secret, SSL options, plus optional nameservers 1 and 2 (shown to customers).

## Product fields

- **WHM package** (required): the exact package name.
- **Feature list**: optional override.
- **IP address**: shared or dedicated.

The service label must be the account's primary domain. The username is derived from it (lowercase, starts with a letter, max 8 characters, a digit is appended on collisions).
