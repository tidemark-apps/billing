# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
