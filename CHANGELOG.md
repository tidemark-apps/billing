# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.6.1] - 2026-11-10

### Added
- `php artisan billing:login-link <email> --reset` prints a link where the user chooses a password, for handing a new hosted install to its owner. How long it lasts is set with `PASSWORD_RESET_EXPIRE` (minutes, default 60).

## [0.6.0] - 2026-11-10

### Added
- Hosted mode (`BILLING_HOSTED=true`), for installs run by a hosting provider: the web installer and plugin uploads are closed, the mail transport comes from the environment (the owner can only set the From name), and `billing:install` needs no password.
- `php artisan billing:login-link <email>`: a signed, expiring, single-use sign-in link. Two-factor authentication still applies.
- Plan limits: a JSON file named in `BILLING_PLAN_FILE` can cap the number of clients and staff users. Existing records keep working over a limit. With no file there are no limits.
- Sign-in links from the host (`BILLING_SSO_PUBLIC_KEY`, `/sso`) for the owner and for support sessions, in the token format Tidemark Analytics uses. Support sessions are logged with the reason and ticket and show a banner.
- A suspended install (`"suspended": true` in the plan file) shows a banner. See `docs/hosting.md`.
- `php artisan billing:usage`: prints the plan, its limits and current usage as JSON.
- The Docker image now runs with a read-only root filesystem (`docker run --read-only --tmpfs /tmp --tmpfs /config:uid=1000,gid=1000`, with `/data` as the only volume). The links into `/data` are made when the image is built, and Laravel's caches go to `/tmp`. A hosted install can start unattended from `BILLING_HOSTED` and `BILLING_ADMIN_EMAIL` without a password.

## [0.5.2] - 2026-11-10

### Fixed
- Cards that hold only a heading and a line of text, such as the two-factor authentication status and "Turn off" cards, had no space below the text. They now have the same padding as the other cards.

## [0.5.1] - 2026-11-10

### Fixed
- Changing a client's email in the admin now also changes the login that used the old address, and moves their open tickets to the new one. Before, new tickets kept being opened (and answered) at the old address.

## [0.5.0] - 2026-11-10

### Added
- An optional knowledge base (Settings, Knowledge base). Articles are written in Markdown with a Write / Markdown editor, organised in categories that nest to any depth, and published at `/kb`. Tables, task lists, code blocks with a copy button and syntax colours, callouts (note, tip, important, warning, caution), numbered steps, images (drop or paste into the editor), collapsible sections and keyboard keys are supported. Readers get search, a side menu, "On this page", previous/next links and a helpful yes/no vote. A switch controls whether guests can read it; drafts and hidden categories are always staff-only. See `docs/knowledgebase.md`.

### Changed

- Cancellations: Settings, Billing has a new Cancellations section. Choose whether customers cancel at the end of the billing term (the default), immediately, or can pick, and whether an immediate cancellation automatically refunds the unused days of the period. Staff can tick the refund per cancellation, and the API reports it in `meta.refund`. See docs/cancellations.md.
- Brand colour: Settings, General has a new Brand colour field. Buttons, links and highlights across the app, emails and PDFs use it instead of Tidemark teal. A colour that would be hard to read is nudged to the closest accessible one for each theme (buttons 3:1 against the page, text 4.5:1), and the form shows what will actually be used.
- Signed-in customers see the order form and the knowledge base inside the client-area layout. Guests keep the plain public pages.
- The sign-in pages, the client-area top bar and public pages show only the business logo or name. The Tidemark mark no longer appears there.
- The client area now uses a top menu (Home, Services, Billing, Support) with account pages in the user menu, a breadcrumb bar, and a dashboard of count tiles with services, tickets, unpaid invoices and account details, instead of the admin-style sidebar.
- Replies to customers on a ticket are now sent as a light, help-desk style email (a "reply above this line" marker, the conversation newest first, a short footer) with a plain-text version, instead of the branded card. Notification emails keep the default template.
- Invoice and quote PDFs have a new, cleaner layout: a large title with the logo on the right, "issued to" and number/date blocks, a minimal items table, right-aligned totals and the company details pinned at the foot of every page.

## [0.4.0] - 2026-11-13

### Changed
- A design pass over every screen so Billing looks and behaves like Tidemark Analytics. Same type, spacing, card, button, tab and form styling everywhere; save buttons sit in the card footer; destructive actions live in a "Danger zone" or an overflow menu; one status pill per item (an overdue invoice reads "Overdue", not "Open" and "Overdue").
- List pages (invoices, quotes, payments, clients, services, products, servers, tickets, staff, plugins, API tokens) have status tabs with live counts, sortable columns and filters that live in the address bar.
- The dashboard leads with what needs attention (overdue, outstanding, revenue with a comparison to last month), and each figure links to the matching list.
- Invoice, client and service pages open with a summary header (status, amount due, key dates) and one primary action. The invoice form has a sticky total and save bar and shows errors next to the field.
- Support tickets: the conversation comes first with the reply box right under it, a Reply / Internal note switch, and the ticket details in a side panel. Customers see plain status wording ("Waiting for us", "We replied").
- Settings are grouped by topic with helper text, "(optional)" labels, set / replace / keep-current for secrets, and an unsaved-changes warning.
- The client area dashboard puts what you owe first, with a Pay button on unpaid invoices. Invoice and quote pages have the amount due and the main action above the fold.
- Orders: a new catalogue with group tabs and plan cards (price, what is included, trial badge, savings on longer billing cycles), a two-column product page with a live "due today" line and a three-step order flow, a review step with Edit, and a clear "Order placed" or "Your free trial has started" confirmation.
- Product descriptions with several lines are shown as a checklist.
- Shown addresses read "City, ST 12345". Page titles with an ampersand no longer show `&amp;`.

### Added
- `php artisan db:seed --class=RichDemoSeeder` fills a throwaway install with a busy set of demo data (clients, services, invoices, quotes, tickets, staff) for screenshots and design reviews.
- `docs/ui-consistency.md` and `docs/ux-research.md` describe the shared look and the UX rules behind this release, for anyone building plugins or themes.

## [0.3.1] - 2026-11-12

### Fixed
- Support email over IMAP now signs in the way mail clients do (SASL PLAIN first, then LOGIN), which some mail servers need. A failed sign-in now reports what the server said to each method and which methods it offered, so the cause is visible.
- "Test connection" on a department now tests what is typed in the form instead of the last saved settings, and says which user, server and encryption it tried (never the password).
- IMAP passwords that start or end with a space are saved as typed.

## [0.3.0] - 2026-11-12

### Added
- Internal accounts. Mark a client as internal (your own team, test customers, accounts on the house) and every one of their services becomes free, new orders and plan changes are free too, and they are left out of revenue, outstanding, overdue and other figures on the dashboard, payment and invoice totals and the API. Their data is kept as is, and turning the flag off puts the normal prices back. See `docs/internal-accounts.md`.
- Optional support tickets (off until you switch them on in Settings → Support). Customers open and follow tickets in the client area, optionally tied to one of their services, with attachments and the extra fields you define per department. Staff get a queue with assignment, status, priority, locking, closing, moving between departments and internal notes the customer never sees.
- Departments, each either listed on the ticket form or unlisted (reachable by direct link or by email), with staff assigned to one department, several or none. Staff only see tickets in their departments and ones assigned to them; admins see everything.
- Email in: each department can have one address, pulled from an IMAP mailbox every minute or piped in from your mail server (`php artisan tickets:pipe`). Replies thread onto the right ticket and are only accepted from the ticket's own customer, so quoting a ticket number can't inject a message.
- Custom ticket fields (text, multi-line, choice, yes/no, number, date), for every department or just one, optionally staff-only.
- Secure attachments: stored privately under random names, type-checked by content, executables, scripts, HTML and SVG refused, size and count limits, and served only to people who can see the ticket as a forced download. See `docs/support-tickets.md`.
- Tickets are in the REST API (customer and staff endpoints, departments and fields) and fire `ticket.created`, `ticket.replied`, `ticket.status_changed` and `ticket.assigned` webhooks.

## [0.2.0] - 2026-11-11

### Added
- Free trials, built in. Give a product a trial length and customers who order it get the service straight away with no invoice; the first invoice is raised when the trial ends, with a reminder email beforehand. One trial per customer per product, optionally requiring a saved payment method, free to cancel, and staff can extend or end a trial early. See `docs/trials-and-plan-changes.md`.
- Upgrades, downgrades and billing-cycle changes, built in. Customers can switch plan from the portal and staff from the admin, with the unused time credited and the rest charged by the day on one invoice. Downgrades wait for the next renewal by default. The hosting package is changed on the server, every change is recorded on the service, and the REST API and webhooks cover all of it.

## [0.1.1] - 2026-11-09

### Fixed
- Admin → Services (and the other lists and pages that show a client or product) no longer fail with a server error after a client or product that only has closed services or old invoices has been deleted. Those records now keep showing the deleted client's name.

## [0.1.0] - 2026-11-09

First release of Tidemark Billing: an open-source billing system and client portal that runs on ordinary PHP shared hosting or in Docker.

### Added
- Invoicing with automatic numbering, taxes by country and region, partial payments, refunds and pay links that work without signing in. Invoices and quotes download as PDFs and are attached to invoice emails.
- Quotes that clients accept online, turning into an invoice and the services it orders.
- Products and recurring services with multi-currency prices, setup fees and billing cycles, plus usage-based billing: per-product meters with per-unit, tiered or package pricing, billed on the renewal invoice.
- An automatic billing run for renewal invoices, reminders, suspending and terminating unpaid services, and cancel-at-period-end.
- A client portal and storefront where customers order, pay, accept quotes, manage services, save payment methods and create API tokens.
- Saved payment methods and autopay with scheduled retries and customer emails when a charge fails.
- Two-factor sign-in for staff and customers (authenticator app and recovery codes), trusted devices, an option to require it for staff, and an administrator-only Staff page.
- An admin area in the Tidemark style: dashboard, clients with "view as client", services, products, servers, invoices, quotes, payments, tax rates and settings.
- A REST API (v1) for customers and staff with an OpenAPI spec, token abilities, rate limiting and signed outbound webhooks.
- A plugin system with events, filters, provisioning modules, payment gateways, migrations, routes and views. Plugins install, upgrade and remove from a zip in the admin area, and a failed upgrade rolls its migrations back.
- Bundled plugins: Stripe, PayPal, cPanel & WHM, DirectAdmin, Enhance and a license server.
- A web installer, `php artisan billing:install` for unattended installs, a release zip for shared hosting and a Docker image (`ghcr.io/tidemark-apps/billing`) that runs the scheduler and queue worker for you.
- Optional Noto Sans font packs so PDFs can show Chinese, Japanese and Korean text.
