# Support tickets

A built-in helpdesk: customers open tickets in the portal or by email, staff answer in the admin.

## Enabling

Set `support.enabled` to `true` (Settings, Support). While it is off, no ticket UI is shown, `tickets:fetch` does nothing and `tickets:pipe` asks the mail server to retry later (exit 75), so mail waits in the queue instead of being lost.

## Departments and addresses

Mail is sorted by **department**. Each department can have **one email address** (the address is unique across departments). That address is:

- where customers write to reach the department,
- the Reply-To of every email we send about its tickets, so replies come back to the right place.

Mail that arrives via IMAP belongs to the department whose mailbox it was read from. Mail that arrives via a pipe is matched by recipient (`Delivered-To`, `X-Original-To`, `Envelope-To`, then `To` and `Cc`), case-insensitively, against the active departments' addresses. Mail for an address nobody uses is rejected. Mail from any department's own address is dropped, which prevents two departments from replying to each other forever.

## Receiving email, option 1: IMAP

In the department's settings enter the mailbox's host, port, encryption, username, password and folder.

| Setting | Notes |
| --- | --- |
| Encryption | `ssl` (port 993, the usual choice), `starttls` (port 143) or `none` (do not use outside a private network). |
| Validate certificate | Leave on. Turn off only for a self-signed certificate you trust. |
| After processing | `seen` marks mail read and leaves it in the mailbox; `delete` removes it. |

Use **Test connection** in the admin: it connects, opens the folder and reports the unread count. It never reads or changes mail.

`tickets:fetch` runs every minute from the scheduler (so the usual single cron entry `* * * * * php artisan schedule:run` is all you need). Each run reads up to 50 unread messages per mailbox, oldest first. Every message is marked read (or deleted) once handled, including ones that were ignored or duplicates, so nothing is looked at twice. If handling a message throws, it is left unread so the next run retries it and the error is shown on the department (`imap_error`, never containing the password). Run it by hand with `php artisan tickets:fetch --department=sales`.

Provider notes:

- **Gmail / Google Workspace**: enable IMAP, turn on 2-step verification and create an **app password**; use that as the password. Google no longer allows plain passwords for IMAP on most accounts.
- **Microsoft 365 / Outlook.com**: basic-authentication IMAP is disabled by default or retired in many tenants. Ask your administrator to allow it for the mailbox and use an app password if MFA is on. **OAuth2 sign-in is not supported**; if you cannot use basic auth, use the pipe or forward the mail to a mailbox elsewhere.
- The PHP side is pure PHP (webklex/php-imap), no `imap` extension is needed. It does need `iconv`, `libxml`, `fileinfo`, `openssl` and `mbstring`, all present in the Docker image.

## Receiving email, option 2: pipe to a program

For hosts where the mail server can hand each message to a command. No IMAP polling, tickets appear immediately.

    php artisan tickets:pipe [--department=slug]

It reads one raw message from STDIN. Without `--department` the recipient headers decide. Nothing from the message is ever printed.

Exit codes (so the mail server does the right thing):

| Code | Meaning |
| --- | --- |
| 0 | Handled: ticket or reply created, or deliberately dropped (duplicate, auto-reply, locked ticket, rejected sender). No bounce. |
| 67 | Unknown recipient (no department for the address). The sender gets a bounce. Auto-replies and bounces are dropped with 0 first, so they never produce a bounce loop. |
| 75 | Temporary failure (support disabled, database down, ...). The mail server keeps the message and retries. |

**cPanel**: Email, Forwarders, Add Forwarder, enter the department address, choose **Advanced Options**, **Pipe to a program** and enter (adjust the PHP and project paths)

    |/usr/local/bin/php /home/USER/billing/artisan tickets:pipe

Pick the PHP binary that matches the project's PHP version (for example `/opt/cpanel/ea-php83/root/usr/bin/php`). The script must be able to read the `.env` of the user that runs it.

**Postfix** (`/etc/aliases`, then run `newaliases`):

    support: "|/usr/bin/php /var/www/billing/artisan tickets:pipe --department=support"

The alias runs as the alias owner or as `nobody` unless you configure otherwise; make sure that user can read the project and write `storage/`.

**Exim** (a forwarder file or `/etc/aliases` with a pipe transport):

    support: |/usr/bin/php /var/www/billing/artisan tickets:pipe --department=support

Other MTAs (qmail, procmail `| php ... tickets:pipe`) work the same: pipe the message in and honour the exit code.

## How replies are threaded

Every email we send about a ticket carries a subject tag such as `[TKT-00001]` (the prefix comes from `support.ticket_prefix`), a Message-ID containing the ticket's id, and a line "##- Please type your reply above this line -##". When mail arrives:

1. If its `In-Reply-To` / `References` name a message we sent or stored, that ticket is used.
2. Otherwise a `[TKT-00001]` tag in the subject names the ticket.
3. Otherwise a new ticket is opened.

Everything from the marker line down, a trailing "On ... wrote:" block and lines starting with `>` are removed from replies, so the ticket shows only what was newly written. HTML-only mail is converted to plain text.

## Spoofing protection

Anyone can write "[TKT-00001]" in a subject. A message is only added to an existing ticket when the sender address is **the ticket's requester** or **a user of the ticket's client**. Anyone else who quotes a ticket number or replies to a message id gets a **new** ticket. Nobody can inject text into somebody else's ticket by email.

Because email From addresses can be forged, also enable SPF/DKIM/DMARC checks on your mail gateway or mailbox provider and reject failing mail before it reaches us. Staff are never accepted as staff by email: a staff address replying by mail is an ordinary sender, and staff answer in the admin.

Other rules applied to every message:

- Already-seen Message-ID: ignored as a duplicate.
- Automatic mail is dropped: `Auto-Submitted` other than `no`, `Precedence: bulk/junk/list`, `X-Autoreply`, `X-Autorespond`, `List-Id`, an empty `Return-Path`, and senders such as mailer-daemon, postmaster and no-reply addresses.
- A **locked** ticket ignores replies. A **closed** ticket is reopened by a valid reply.
- With `support.reject_unknown_senders` on, mail from addresses that do not belong to a customer account does not open tickets.

## Who sees what

- Customers see their own tickets (and their client's, for users of that client), their replies and staff replies. They never see internal notes or system lines (assignment, status, department moves).
- Staff see tickets in departments they belong to and tickets assigned to them. Admins see everything.
- **Internal notes** are staff-only. The customer is never emailed about them, and files attached to a note stay staff-only.
- **Locking** a ticket stops the customer replying (by portal or email) while staff can still work on it.

## Attachments

Attachments (portal uploads and email attachments) are treated as hostile:

- stored on the private `tickets` disk (not under `public/`), under a random name that is not derived from the upload;
- the type is detected from the file's content; the name and type the sender claims are not trusted;
- executables, scripts and web-page types (php, exe, js, html, svg, xml, ...) are refused, also when hidden in a double extension such as `x.php.jpg`;
- optionally narrowed to an allow-list of extensions (`support.allowed_extensions`);
- limited in size (`support.max_attachment_mb`, default 10) and number (`support.max_attachments`, default 5);
- served only through an authorised controller, as a forced download with `X-Content-Type-Options: nosniff` and `Content-Security-Policy: default-src 'none'; sandbox`, so an uploaded HTML or SVG file can never run in the site's origin.

By email, a bad attachment never fails the message: it is dropped, the ticket or reply is still created, and a line such as `[Attachment 'x.exe' was not accepted: is a type of file that cannot be attached]` is appended to the message body.

**There is no antivirus scanning.** Run one at the mail gateway (for example ClamAV via your MTA, or your provider's filtering) and be careful with downloaded files.

## Settings

| Key | Default | Meaning |
| --- | --- | --- |
| `support.enabled` | `false` | Master switch. |
| `support.ticket_prefix` | `TKT-` | Prefix of ticket numbers and the subject tag. |
| `support.reject_unknown_senders` | `false` | Do not open tickets from addresses without a customer account. |
| `support.max_attachments` | `5` | Files per message. |
| `support.max_attachment_mb` | `10` | Size per file. |
| `support.allowed_extensions` | empty | Comma-separated allow-list; empty means anything not blocked. |
| `support.notify_staff` | `true` | Email department staff about new tickets and replies. |
| `support.notify_customer_on_close` | `true` | Tell the customer when a ticket is closed. |
