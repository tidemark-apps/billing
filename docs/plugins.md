# Writing plugins

Everything beyond core invoicing, quoting and the client area is a plugin: payment gateways, hosting-panel provisioning, license servers, your own automations. A plugin is a folder. Drop it in `plugins/`, open **Admin → Plugins** and press **Enable**. No Composer, no build step, so it works on shared hosting.

```
plugins/acme-widgets/
├── plugin.json
├── src/AcmePlugin.php            namespace Plugins\Acme\  (autoloaded from src/)
├── resources/views/              views are addressed as acme-widgets::name
├── database/migrations/          run automatically when the plugin is enabled
└── routes/{admin,portal,api,public,webhook}.php
```

## Installing, upgrading and removing

> **Trust warning.** A plugin is arbitrary PHP that runs with full access to your database, files and secrets. Only install plugins from sources you trust, and read code you did not write. The upload checks below guard against malformed or hostile *archives*, not against a malicious *plugin*.

**From the admin UI** (administrators only): **Admin → Plugins → Upload**. The zip may have `plugin.json` at its root or inside a single top-level folder (a GitHub "Download ZIP" works). The archive is validated (size, file count and unpacked-size limits, no `..`/absolute paths or symlinks, required manifest keys `slug`, `name`, `version`, `namespace`, `provider`, the provider class under `src/`, and the `requires.billing` constraint), unpacked into a temp folder, then moved into place. Uploaded plugins go to `BILLING_EXTRA_PLUGINS_PATH` if set (Docker: `/data/plugins`), otherwise `plugins/`. If that folder is not writable (common on shared hosting) you get a message with the fix: make it writable, point `BILLING_EXTRA_PLUGINS_PATH` at a writable folder, or upload by FTP. Without the PHP `zip` extension, unzip by hand into `plugins/<slug>/` and press Enable.

* **Upgrade**: uploading a higher version of an installed slug shows *old → new* and asks you to confirm. Settings and the enabled state are kept, the old folder is copied to `storage/app/plugin-backups/`, and (if enabled) the new migrations run. If they fail, the migrations are undone and the old files are put back (see *Failed migrations* below). Same or lower versions need an explicit "reinstall"/"downgrade" confirmation (database changes from newer migrations are not reverted).
* **Bundled plugins** (those in the release's `plugins/` folder, or any folder not installed by upload) can be disabled but not deleted from the UI. Uploading over one asks for confirmation; with `BILLING_EXTRA_PLUGINS_PATH` the uploaded copy shadows the bundled one, which is left untouched.
* **Remove**: for a *disabled* uploaded plugin, **Delete** runs its `uninstall()` hook, deletes its files (a backup goes to `storage/app/plugin-backups/`), forgets the plugin record, and optionally erases its saved settings (tick the box before confirming).
* **Failed migrations are rolled back.** Upgrade, first-time Enable, the "Apply update" button and `php artisan plugin install|enable` all run a plugin's migrations through one runner. If a migration fails, the runner finds the migrations that completed in that operation (by comparing the `migrations` table before and after), runs their `down()` in reverse order and removes their rows, so the database is not left ahead of the code. Then the old files, plugin version and enabled state are restored (settings are never touched), and the error says what was rolled back and what was not. A migration without `down()`, or whose `down()` throws, is named in the message with the original error, and its row stays in `migrations` because its changes are still in the database (undo it by hand). On **SQLite and PostgreSQL** (transactional DDL) the whole run is also one database transaction, so a failure leaves nothing behind at all. **MySQL/MariaDB cannot roll back DDL**: there the `down()` rollback is all there is, and the migration that failed itself may be partly applied (for example the first of two `Schema::create` calls). Always write a complete, safe `down()`, and keep each migration small. The first-time `install()` hook is covered too: if it throws, the migrations are reverted and the plugin stays not installed.
* Installing a plugin whose migrations have no `down()` shows a (non-blocking) warning in the UI and CLI.
* Set `BILLING_ALLOW_PLUGIN_UPLOAD=false` to turn uploading and deleting off in the UI. Limits: `BILLING_PLUGIN_UPLOAD_MAX_MB` (default 16; also check `upload_max_filesize`/`post_max_size`), and `billing.plugin_upload.max_files` / `max_unpacked_mb` in `config/billing.php`.
* Installs, upgrades, downgrades and removals are written to the activity log.

**From the command line**:

```
php artisan plugin install /path/to/plugin.zip      # or an https:// URL (size capped, https only)
php artisan plugin install plugin.zip --force       # skip the upgrade/downgrade prompt
php artisan plugin remove acme-widgets [--drop-settings] [--force]
```

## plugin.json

```json
{
  "slug": "acme-widgets",
  "name": "Acme Widgets",
  "version": "1.0.0",
  "description": "Sells widgets.",
  "author": "Acme",
  "namespace": "Plugins\\Acme\\",
  "provider": "Plugins\\Acme\\AcmePlugin",
  "requires": { "billing": ">=0.1" }
}
```

The `slug` is the folder name's identity (lowercase letters, numbers, dashes). Classes under `namespace` are autoloaded from `src/`. If the plugin needs third-party packages, ship a `vendor/autoload.php` inside the plugin folder; it is loaded for you.

## The plugin class

```php
namespace Plugins\Acme;

use App\Plugins\Plugin;

class AcmePlugin extends Plugin
{
    public function register(): void   // early: register gateways/modules/bindings
    {
        $this->registerModule(WidgetModule::class);
    }

    public function boot(): void       // everything else is ready: routes, views, menus, listeners
    {
        $this->loadViews();
        $this->loadMigrations();
        $this->loadRoutes('routes/admin.php', 'admin');
        $this->adminMenu('Widgets', 'admin.acme-widgets.index', 'package');
    }

    public function fields(): array    // optional settings form under Admin → Plugins → Settings
    {
        return [['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true]];
    }
}
```

`$this->setting('api_key')` reads a value saved from that form. Password fields are encrypted at rest.

Only enabled plugins are loaded. `install()` runs once the first time a plugin is enabled (after its migrations); `uninstall()` is available for cleanup.

### Route areas

| Area      | URL prefix                  | Route names          | Guard                    |
|-----------|-----------------------------|----------------------|--------------------------|
| `admin`   | `/admin/x/{slug}`           | `admin.{slug}.*`     | signed-in staff          |
| `portal`  | `/portal/x/{slug}`          | `portal.{slug}.*`    | signed-in customer       |
| `api`     | `/api/v1/x/{slug}`          | `api.{slug}.*`       | none (add `auth.api`)    |
| `public`  | `/x/{slug}`                 | `{slug}.*`           | none                     |
| `webhook` | `/webhooks/{slug}`          | `webhook.{slug}.*`   | none, no CSRF            |

## Extension points

### 1. Domain events: react to things happening

Every business event is a class in `App\Events` carrying the model. Listen with `$this->listen()`; the same events feed outbound webhooks.

| Event | When |
|---|---|
| `ClientCreated` | a client account is created |
| `InvoiceCreated` / `InvoicePaid` / `InvoiceVoided` | issued / paid in full / voided |
| `PaymentReceived` / `PaymentRefunded` | money recorded / refunded |
| `QuoteSent` / `QuoteAccepted` / `QuoteDeclined` | quote lifecycle |
| `ServiceCreated` / `ServiceActivated` / `ServiceSuspended` / `ServiceUnsuspended` / `ServiceCancelled` / `ServiceTerminated` | service lifecycle |
| `ProvisioningFailed` | a module call failed |

```php
$this->listen(\App\Events\InvoicePaid::class, fn ($e) => Slack::notify("Paid: {$e->model->number}"));
```

### 2. Filters: change a value

```php
use App\Plugins\Hooks;
Hooks::filter('my.filter', fn (string $value, $context) => strtoupper($value));
$value = Hooks::apply('my.filter', $value, $context);   // in your own code
```

### 3. UI slots: add pages and panels

`$this->adminMenu()` / `portalMenu()` add sidebar links. `$this->extend($slot, ['view' => 'slug::partial', 'when' => fn ($ctx) => …])` adds a panel to a core page:

| Slot | Where | Data |
|---|---|---|
| `admin.dashboard`, `portal.dashboard` | dashboards | – |
| `admin.service`, `portal.service` | service page | `service` |
| `admin.client` | client page | `client` |
| `admin.invoice`, `portal.invoice` | invoice page | `invoice` |
| `admin.product` | product form | `product` |

### 4. Provisioning modules

A module turns a paid service into something real. Extend `App\Plugins\AbstractModule` and implement `key()`, `label()` and `create()`; every other lifecycle method has a harmless default.

```php
class WidgetModule extends AbstractModule
{
    public function key(): string { return 'widgets'; }
    public function label(): string { return 'Widget factory'; }
    public function productFields(): array { return [['key' => 'size', 'label' => 'Size', 'type' => 'select', 'options' => ['s' => 'Small', 'l' => 'Large']]]; }

    public function create(Service $service): ProvisionResult
    {
        $id = Widgets::make($service->product->moduleOption('size'));
        return ProvisionResult::ok('Created', ['widget_id' => $id], ['admin_password' => $this->generatePassword()]);
    }
    public function suspend(Service $service, ?string $reason = null): ProvisionResult { /* … */ }
}
```

- Modules appear in the product form's **Module** dropdown; `productFields()` are rendered there and stored in `products.module_config` (`$product->moduleOption('size')`).
- `usesServers()` → true makes the module use **Admin → Servers** records (hostname, credentials, `serverFields()`); the core picks a server with free capacity and sets `$service->server`.
- `data` is shown to staff and the client; `secrets` are encrypted and only shown on request.
- Return `ProvisionResult::fail($message)` instead of throwing: the service stays pending, the error is shown to staff with a **Retry** button, and a `ProvisioningFailed` event fires.
- `adminActions()` / `clientActions()` + `runAction()` add buttons (e.g. *Log in to cPanel*). Return `ProvisionResult::redirect($url)` to send the browser away.

The core calls modules through `App\Services\ProvisioningService`; it activates a service when its first invoice is paid, suspends it when a renewal goes unpaid, unsuspends it on payment, and terminates it per your settings.

### 5. Payment gateways

Extend `App\Plugins\AbstractGateway`:

```php
class AcmePayGateway extends AbstractGateway
{
    public function key(): string { return 'acmepay'; }
    public function label(): string { return 'AcmePay'; }
    public function fields(): array { return [['key' => 'secret', 'label' => 'Secret key', 'type' => 'password', 'required' => true]]; }

    public function checkout(Invoice $invoice, CheckoutContext $c): CheckoutResult
    {
        $url = AcmePay::session($invoice->balance(), $invoice->currency, $c->successUrl, $c->cancelUrl);
        return CheckoutResult::redirect($url);
    }
    public function complete(Request $r, Invoice $invoice): ?Payment { /* customer came back: verify, then record */ }
    public function webhook(Request $r): Response { /* verify signature, record payment/refund */ return response('ok'); }
}
```

- Record money with `app(PaymentService::class)->record($invoice, 'acmepay', $amountMinor, $providerReference)`. It is **idempotent on (gateway, reference)**, so calling it from both the browser return and the webhook is safe. Refunds seen at the provider use `recordRefund()`.
- Admins enable the gateway and fill `fields()` under **Settings → Payments**. The webhook URL is `/webhooks/gateways/{key}`.
- Amounts are integers in the currency's minor unit; `App\Support\Money` converts and formats.

#### Saved payment methods and autopay (optional)

A gateway can also implement `App\Contracts\SupportsSavedMethods` (`beginSetup`, `completeSetup`, `charge`, `delete`). Such gateways appear on the portal's **Payment methods** page, and customers can opt in to **automatic payment**. The core stores only the provider's token plus brand/last4/expiry (`payment_methods` table), never a card number.

- `charge()` returns a `ChargeResult` (`success`, `failed`, `requiresAction`, `pending`) and must not record the payment; `App\Services\PaymentMethods::pay()` does that through `PaymentService` (idempotent on gateway + reference). Pass the `$idempotencyKey` on to the provider.
- In `webhook()` use `PaymentMethods::store()`, `updateDetails()` and `markRemoved()` to keep tokens in sync.
- `App\Services\AutoPayer` (hourly `billing:autopay`, plus the `InvoiceCreated` listener) charges the customer's default autopay method **on the invoice due date** (Settings → Billing → Automatic payments: days before due, charge renewals at creation, retry days `1,3,5` after the first attempt, max attempts `4`). Failures email the customer; after the last attempt autopay stops for that invoice and the customer and admin are notified. `requiresAction` (3-D Secure) pauses autopay and emails the customer to pay manually.


### 6. Scheduled work

```php
$this->schedule(fn (Schedule $s) => $s->call(fn () => Widgets::sync())->hourly());
```

### 7. Reporting metered usage (optional)

Products can have **meters** (Admin → Products → Metered usage): API calls, GB of bandwidth, seats. Usage is billed in arrears,
one invoice line per meter, on the renewal invoice. A provisioning module that can read consumption from the remote system
implements `App\Contracts\ReportsUsage` and the billing run pulls it daily:

```php
class WidgetModule extends AbstractModule implements \App\Contracts\ReportsUsage
{
    public function pullUsage(Service $service, CarbonInterface $from, CarbonInterface $to): array
    {
        // $from inclusive, $to exclusive (midnight boundaries). Return meter key => quantity.
        return ['bandwidth_gb' => Widgets::bandwidth($service->data('username'), $from, $to)];
    }
}
```

- "sum" meters: return what was consumed inside the window. "max" / "last" meters: return the observed value.
- Unknown meter keys are ignored. Each window is recorded once (idempotency key `pull:{from}:{to}`), so re-running is safe.
- `php artisan billing:usage:pull` runs only this step; `billing:run` includes it.
- Plugins and integrations can also push usage: `app(\App\Services\UsageService::class)->record($service, 'api_calls', 120, now(), 'job-8841', 'myplugin')`.
  Repeating a call with the same idempotency key (per service and meter) returns the existing record and does nothing else.
  Every new record fires the `usage.recorded` domain event (and so the outbound webhook of the same name).
- Prices are stored in **micro-minor units** (1 = one millionth of the currency's minor unit, so `$0.002` is `200000`), quantities have
  six decimals, and a line is rounded once to the minor unit. `App\Support\UsageMath` does the exact (BCMath) arithmetic.
- Settings: `billing.usage_zero_lines` (show zero-cost usage lines, default off) and `billing.usage_invoice_days_before`
  (invoice metered services this many days before the period ends, default 0; usage recorded after the cut-off goes on the next invoice).

## Testing a plugin

```php
class WidgetTest extends Tests\TestCase
{
    use RefreshDatabase;

    public function test_it_provisions(): void
    {
        $this->enablePlugin('acme-widgets');         // runs migrations, registers and boots it
        // …order a product with module 'widgets', pay the invoice, assert the service is active
    }
}
```

Look at `plugins/license-server` (a module, public API, admin and client UI, migrations) and `plugins/stripe` (a gateway) for complete examples.

## PDF filter: `pdf.data`

Invoice and quote PDFs are rendered by `App\Services\PdfRenderer` (dompdf, pure PHP). Before the template (`resources/views/pdf/invoice.blade.php` or `quote.blade.php`) is rendered, the view data passes through the `pdf.data` filter so a plugin can add or change values:

```php
use App\Plugins\Hooks;

Hooks::filter('pdf.data', function (array $data, string $type, $document) {
    // $type is 'invoice' or 'quote'; $document is the Invoice or Quote model.
    $data['paymentInstructions'] .= "\nPlease quote ".$document->number.' with your transfer.';
    return $data;
});
```

Keys include `company` (name, address, email, tax_id, logo as a data URI), `paper` (`a4` or `letter`), `accent`, `paymentInstructions`, `payUrl` and the document itself. Always return the array. Remote images are disabled in PDFs: embed images as `data:` URIs.
