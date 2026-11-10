# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
