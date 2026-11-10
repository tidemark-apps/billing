<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="public/assets/logo/tidemark-horizontal-dark.svg">
    <img src="public/assets/logo/tidemark-horizontal.svg" alt="Tidemark" width="260">
  </picture>
</p>

# Tidemark Billing

An open-source billing system and client portal that runs on **ordinary PHP shared hosting** or in **Docker**. Invoicing, quotes and recurring billing are built in; everything else (payment providers, hosting-panel provisioning, license servers, your own automations) is a **plugin**. A full REST API lets you build your own frontend.

Built with Laravel, in the same visual language as [Tidemark Analytics](https://www.tidemarkanalytics.com).

## What's in the box

- **Invoicing** with automatic numbering, taxes by country/region, partial payments, refunds, **PDF downloads** (also attached to emails; Chinese, Japanese and Korean text via an optional font pack, see [`docs/pdf-fonts.md`](docs/pdf-fonts.md)), printable pages and unguessable pay links that work without a login
- **Quotes** that clients accept online with one click, converting straight into an invoice and the services it orders
- **Products and recurring services** with multi-currency prices, setup fees and billing cycles from monthly to every 3 years
- **Free trials and plan changes**: per-product trials that convert to a paid invoice, and prorated upgrades, downgrades and cycle changes for staff and customers
- **Usage-based billing**: per-product meters (per unit, tiered or package pricing) billed in arrears on the renewal invoice
- **Saved payment methods and autopay** with scheduled retries (Stripe and PayPal)
- **Two-factor sign-in** (authenticator app and recovery codes) for staff and customers, see [`docs/two-factor.md`](docs/two-factor.md)
- **Automatic billing run**: renewal invoices, reminder emails, suspension and termination of unpaid services, cancel-at-period-end
- **Client portal** where customers order services, pay invoices, accept quotes, manage services and create API tokens
- **Admin area**: dashboard, clients (with "view as client"), services, products, servers, invoices, quotes, payments, tax rates, settings
- **REST API v1** for customers and staff with an OpenAPI spec, token abilities, rate limiting and **signed outbound webhooks**
- **Plugin system**: events, filters, UI slots, provisioning modules, payment gateways, migrations, routes and views, all from a folder you drop in
- Light and dark themes, keyboard friendly, mobile ready

### Bundled plugins

| Plugin | What it does |
|---|---|
| **Stripe** | Card payments through hosted Stripe Checkout, webhooks and refunds |
| **PayPal** | PayPal checkout (Orders v2), webhooks and refunds |
| **cPanel / WHM** | Create, suspend, unsuspend, terminate and upgrade hosting accounts; one-click panel login |
| **DirectAdmin** | The same for DirectAdmin servers |
| **Enhance** | The same for Enhance control panel |
| **License Server** | Issues license keys for products, with a public verify/activate API and offline-verifiable signatures |

## Install on shared hosting (no SSH)

1. Download the latest `tidemark-billing-x.y.z.zip` from Releases (dependencies are bundled), or build it yourself: `scripts/build-release.sh 0.1.0` → `dist/tidemark-billing-0.1.0.zip`.
2. Upload and unzip it on your host. **Point the domain's document root at the `public/` folder.** If your host won't let you, upload to the web root as-is: the bundled root `.htaccess` forwards to `public/` and blocks internals.
3. Visit `https://your-domain/install`. The page checks the server, connects your database (SQLite needs no setup), runs the migrations and creates your admin account.
4. Add a cron job (cPanel → *Cron Jobs*):
   ```
   * * * * * php /home/you/billing/artisan schedule:run >> /dev/null 2>&1
   ```
   No command-line cron? **Admin → Settings → System** gives you a secret URL to call every minute instead.
5. Under **Settings → Email** add your SMTP details, then enable a payment plugin under **Plugins** and fill in its keys in **Settings → Payments**.

Requirements: PHP 8.3+, `mbstring`, `openssl`, `pdo` + one of `pdo_sqlite` / `pdo_mysql` / `pdo_pgsql`, `fileinfo`, `ctype`, `tokenizer`, `xml`, `curl`, `bcmath`, `zip` (plugin upload). (`sodium` for the License Server plugin, `intl` recommended for currency formatting.)

## Install with Docker

```bash
docker compose up -d
```

The image is `ghcr.io/tidemark-apps/billing` (tags `latest`, `0.1` and exact versions such as `0.1.0`; amd64 and arm64). Open <http://localhost:8080/install>. Or skip the installer by setting `BILLING_ADMIN_EMAIL` and `BILLING_ADMIN_PASSWORD` in [`docker-compose.yml`](docker-compose.yml). Data (the SQLite database, `.env`, logs and extra plugins in `/data/plugins`) lives in the `billing-data` volume. The image runs the scheduler and a queue worker for you. For MariaDB see [`docker-compose.mysql.yml`](docker-compose.mysql.yml).

## Using the API

Create a token under **Admin → API & webhooks** (staff) or **Portal → API access** (customers), then:

```bash
curl -H "Authorization: Bearer $TOKEN" https://billing.example.com/api/v1/me
```

The full reference is in [`docs/api.md`](docs/api.md) and `resources/openapi.json` (served at `/api/v1/openapi.json`). Webhooks are signed with HMAC-SHA256 so you can verify them.

## Writing a plugin

See [`docs/plugins.md`](docs/plugins.md). In short: a folder with a `plugin.json` and a class that registers payment gateways, provisioning modules, routes, views, menus and event listeners.

## Development

```bash
composer setup      # install, create .env, migrate
php artisan serve   # then open /install
php artisan test
vendor/bin/pint
```

Useful commands: `php artisan billing:run` (the daily billing run), `php artisan plugin list|enable|disable|install|remove`, `php artisan billing:install` (unattended install), `php artisan billing:fonts:install` (optional CJK fonts for PDFs).

## Project layout

```
app/Services      invoicing, payments, ordering, quotes, provisioning, the billing run
app/Plugins       plugin manager, registries, base classes, hooks, UI extension slots
app/Contracts     PaymentGateway and ProvisioningModule interfaces
app/Events        the business events plugins and webhooks react to
plugins/          bundled plugins (each a self-contained folder)
routes/           web.php (auth, pay links), admin/, portal.php, store.php, api.php
```

## Status

Version 0.1. PDFs print Chinese, Japanese and Korean text once the optional Noto Sans font packs are installed (about 22 MB downloaded on demand, nothing extra in the release zip; see [`docs/pdf-fonts.md`](docs/pdf-fonts.md)); bold CJK text renders at regular weight. Not yet included: multi-language UI, saving cards through PayPal (wallet only), and 2FA enrolment over the API. The control-panel plugins follow the vendors' published API documentation but have not been tested against live servers; each plugin's README lists what could not be verified. Plugins can be installed from a zip in the admin UI (admin role only; plugins run arbitrary PHP, so install only code you trust). Issues and pull requests are welcome.

## License

[MIT](LICENSE). Icons are [Lucide](https://lucide.dev) (ISC) and the typeface is Geist (OFL).
