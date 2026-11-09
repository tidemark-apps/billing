<?php

namespace App\Http\Controllers\Api\Support\OpenApi;

/**
 * Per-route documentation for the OpenAPI document, keyed by route name without the "api." prefix.
 * Everything else (path, method, path parameters, security, abilities, error responses, pagination parameters) is
 * derived from the route itself by Builder, so a new route only needs one entry here: [summary, response, body?, query?].
 *
 * Responses: one('Client'), page('Client'), many('Client'), created('Client'), none(), raw('Name'), or a custom array.
 * Run `php artisan api:openapi` after changing this file or the routes (a test fails when resources/openapi.json is stale).
 */
class Operations
{
    /** @return array<string, array<int, mixed>> */
    public static function all(): array
    {
        $cycles = 'one_time|monthly|quarterly|semi_annually|annually|biennially|triennially';

        return [
            // ---- meta, authentication, account (public and any-token) ---------------------------------------------
            'index' => ['API root: name and version', self::raw(Dsl::obj(['name' => 'string', 'version' => 'string', 'api' => 'string']))],
            'openapi' => ['This OpenAPI document', self::raw(['type' => 'object'])],
            'client.auth.token' => ['Exchange email and password (and, with two-factor authentication, an otp) for a bearer token', self::created('TokenResponse') + ['errors' => [
                '401' => 'invalid_credentials; or, for accounts with two-factor authentication, otp_required (no otp sent) / invalid_otp (wrong, replayed or already used code)',
                '403' => 'two_factor_enrolment_required: staff must set up two-factor authentication first (Settings → Security requires it)',
            ]], Dsl::obj([
                'email!' => 'email', 'password!' => 'string', 'name' => ['string', 'A label for the token'],
                'otp' => ['string', 'Required when the account has two-factor authentication: the 6-digit authenticator code, or an unused recovery code. Always send it as a string. 5 wrong codes per 5 minutes (per user and IP) are allowed.'],
                'abilities' => ['string[]', 'Limit the token, e.g. ["invoices.read"]. Must be valid for the account: customer abilities for customers, staff abilities for staff (see GET /admin/abilities). Omit for full access.'],
                'expires_in_days' => 'integer',
            ])],
            'client.auth.register' => ['Create a client account and get a token (when registration is open)', self::created('TokenResponse'), Dsl::obj([
                'name!' => 'string', 'email!' => 'email', 'password!' => 'string', 'company_name' => 'string', 'country' => 'string', 'currency' => 'string',
            ])],
            'client.auth.revoke' => ['Revoke the token used for this request', self::none()],
            'client.me.show' => ['Current user and client profile', self::one('User')],
            'client.me.update' => ['Update profile and billing details', self::one('User'), Dsl::obj([
                'name' => 'string', 'email' => 'string', 'password' => 'string', 'current_password' => 'string',
                'client' => ['object', 'Billing details: name, company_name, email, phone, address_1, address_2, city, state, postcode, country, tax_id'],
            ])],

            // ---- client API (customer tokens) ---------------------------------------------------------------------
            'client.products.index' => ['List visible products with prices (public)', self::page('Product'), null, ['group_id' => 'string', 'q' => 'string']],
            'client.products.show' => ['Get a product by id or slug (public)', self::one('Product')],
            'client.gateways.index' => ['Payment methods available to the client', self::many(Dsl::obj(['key' => 'string', 'title' => 'string', 'description' => 'string'])), null, ['currency' => 'string']],
            'client.orders.store' => ['Order a product: creates a pending service and an invoice', self::created(Dsl::obj(['service' => 'Service', 'invoice' => 'Invoice', 'pay_url' => 'string'])), Dsl::obj(['product_id!' => 'integer', 'billing_cycle!' => $cycles, 'label' => 'string'])],
            'client.services.index' => ['List your services', self::page('Service'), null, ['status' => 'string']],
            'client.services.show' => ['Get a service by uuid. meta.usage (current usage and estimated charge) appears for metered products when the token has usage.read', ['kind' => 'one', 'schema' => 'Service', 'extra' => ['meta' => Dsl::obj(['actions' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']], 'usage' => 'UsageSummary'])]]],
            'client.services.usage' => ['Usage of one of your services this period with the estimated charge (read-only; billed in arrears with the renewal invoice)', self::one('UsageSummary')],
            'client.services.cancel' => ['Cancel a service (at period end unless immediately)', self::one('Service'), Dsl::obj(['immediately' => 'boolean', 'reason' => 'string'])],
            'client.services.action' => ['Run a module action such as a control-panel login', self::one('ActionResult')],
            'client.invoices.index' => ['List your invoices', self::page('Invoice'), null, ['status' => 'string']],
            'client.invoices.show' => ['Get an invoice by uuid', self::one('Invoice')],
            'client.invoices.pdf' => ['Download the invoice as a PDF (attachment; ?inline=1 to display)', self::file()],
            'client.invoices.pay' => ['Start paying: returns the gateway checkout URL', self::one(Dsl::obj(['checkout_url' => 'string'])), Dsl::obj(['gateway!' => 'string'])],
            'client.invoices.pay-saved' => ['Pay an open invoice with one of your saved payment methods. A declined charge is a 422 payment_failed', self::one('SavedPaymentOutcome'), Dsl::obj(['payment_method!' => ['uuid', 'uuid of a saved payment method (GET /payment-methods)']])],
            'client.payment-methods.index' => ['Your saved payment methods (default first) and, in meta.gateways, the providers that can save a new one', self::many('PaymentMethod') + ['extra' => ['meta' => Dsl::obj(['gateways' => 'SavedMethodGateway[]'])]]],
            'client.payment-methods.show' => ['Get a saved payment method', self::one('PaymentMethod')],
            'client.payment-methods.setup' => ['Start saving a payment method: returns the provider\'s hosted setup URL to send the customer to. The method is stored when the customer returns to the client area (they may need to sign in there)', self::one(Dsl::obj(['redirect_url' => 'string'])), Dsl::obj(['gateway!' => ['string', 'Key from meta.gateways of GET /payment-methods'], 'autopay' => ['boolean', 'Turn automatic payment on for the new method']])],
            'client.payment-methods.update' => ['Make a method the default (is_default: true) and/or switch automatic payment on or off for it', self::one('PaymentMethod'), Dsl::obj(['is_default' => ['boolean', 'Only true is accepted: pick another method to change the default'], 'autopay' => 'boolean'])],
            'client.payment-methods.destroy' => ['Remove a saved method at the provider and here', self::one('PaymentMethodRemoval')],
            'client.quotes.index' => ['List your quotes', self::page('Quote'), null, ['status' => 'string']],
            'client.quotes.show' => ['Get a quote by uuid', self::one('Quote')],
            'client.quotes.pdf' => ['Download the quote as a PDF (attachment; ?inline=1 to display)', self::file()],
            'client.quotes.accept' => ['Accept a quote (may create the invoice)', self::one('Quote'), Dsl::obj(['name' => 'string'])],
            'client.quotes.decline' => ['Decline a quote', self::one('Quote')],
            'client.payments.index' => ['List your payments and refunds', self::page('Payment'), null, ['invoice_id' => 'string']],

            // ---- admin: dashboard, activity -----------------------------------------------------------------------
            'admin.dashboard' => ['Dashboard statistics: revenue this month, outstanding, overdue, active services, recent activity', self::one('Dashboard')],
            'admin.activity.index' => ['Activity log (audit trail), newest first', self::page('ActivityLog'), null, [
                'action' => ['string', 'Exact action, or a prefix with * such as invoice.*'], 'user_id' => 'integer', 'subject_type' => ['string', 'client, invoice, quote, service, payment, product, server, ...'],
                'subject_id' => 'integer', 'from' => 'date', 'to' => 'date',
            ]],

            // ---- admin: clients and portal logins -----------------------------------------------------------------
            'admin.clients.index' => ['List clients', self::page('Client'), null, ['status' => 'active|inactive|closed', 'q' => ['string', 'Name, company or email']]],
            'admin.clients.store' => ['Create a client (send password to also create a portal login)', self::created('Client'), 'ClientInput'],
            'admin.clients.show' => ['Get a client', self::one('Client')],
            'admin.clients.update' => ['Update a client, including its status and internal notes', self::one('Client'), 'ClientInput'],
            'admin.clients.destroy' => ['Delete a client (administrators only; not while it has live services or unpaid invoices)', self::none()],
            'admin.clients.tax-rate' => ['The tax rate that would apply to this client', self::one('TaxLookup')],
            'admin.clients.users.index' => ['List the portal logins of a client', self::page('User'), null, ['is_active' => 'boolean']],
            'admin.clients.users.store' => ['Add a portal login to a client', self::created('User'), 'UserCreateInput'],
            'admin.clients.payment-methods.index' => ['List a client\'s saved payment methods (tokens are never returned)', self::many('PaymentMethod')],
            'admin.clients.payment-methods.destroy' => ['Remove one of a client\'s saved payment methods at the provider and here', self::one('PaymentMethodRemoval')],
            'admin.autopay.attempts' => ['Automatic payment attempts, newest first. Settings are at /settings/autopay', self::page('AutopayAttempt'), null, [
                'status' => 'success|failed|requires_action|pending|final', 'invoice_id' => 'integer', 'client_id' => 'integer', 'gateway' => 'string', 'from' => ['date', 'Attempted on or after'], 'to' => ['date', 'Attempted on or before'],
            ]],
            'admin.users.show' => ['Get a portal login', self::one('User')],
            'admin.users.update' => ['Update a portal login\'s name or email', self::one('User'), 'UserInput'],
            'admin.users.password' => ['Set a new password for a portal login (revokes its API tokens)', self::one('User'), 'PasswordInput'],
            'admin.users.password-reset-link' => ['Email the login a password reset link', self::one('ActionResult')],
            'admin.users.activate' => ['Re-enable a deactivated portal login', self::one('User')],
            'admin.users.deactivate' => ['Deactivate a portal login (it can no longer sign in or use the API; its tokens are revoked)', self::one('User')],
            'admin.users.impersonate' => ['Issue a short-lived customer token that acts as this login ("view as customer")', self::created('ImpersonationToken'), 'ImpersonationInput'],

            // ---- admin: staff accounts (administrators only) ------------------------------------------------------
            'admin.staff-users.index' => ['List staff accounts (administrators only)', self::page('StaffUser'), null, ['is_active' => 'boolean', 'role' => 'admin|staff']],
            'admin.staff-users.store' => ['Create a staff account (administrators only). Send a password, or send_link: true to email a link to choose one', self::created('StaffUser'), 'StaffUserCreateInput'],
            'admin.staff-users.show' => ['Get a staff account (administrators only)', self::one('StaffUser')],
            'admin.staff-users.update' => ['Update a staff account\'s name, email or role (administrators only; the last active administrator cannot be demoted, 422)', self::one('StaffUser'), 'StaffUserInput'],
            'admin.staff-users.activate' => ['Re-enable a deactivated staff account (administrators only)', self::one('StaffUser')],
            'admin.staff-users.deactivate' => ['Deactivate a staff account (administrators only): it cannot sign in or use the API, its tokens are revoked and database sessions ended. Not yourself and not the last active administrator (422)', self::one('StaffUser')],
            'admin.staff-users.password-reset-link' => ['Email a staff member the password reset link (administrators only)', self::one('ActionResult')],
            'admin.staff-users.two-factor.reset' => ['Turn off a staff member\'s two-factor authentication after a lost phone (administrators only, not for yourself). Their API tokens are kept unless revoke_api_tokens is true (do that for a possibly compromised account)', self::one('StaffUser'), Dsl::obj(['revoke_api_tokens' => ['boolean', 'Also revoke every API token of this user']])],

            // ---- admin: services ----------------------------------------------------------------------------------
            'admin.services.index' => ['List services', self::page('Service'), null, ['status' => 'pending|active|suspended|cancelled|terminated', 'client_id' => 'integer', 'product_id' => 'integer', 'q' => ['string', 'Label']]],
            'admin.services.store' => ['Create a service for a client: places an order (pending service + invoice), or only the service with create_invoice=false', self::created('ServiceCreated'), 'ServiceCreateInput'],
            'admin.services.show' => ['Get a service with its available admin actions and recent logs. meta.usage (current usage and estimated charge) appears for metered products when the token has usage.read', ['kind' => 'one', 'schema' => 'Service', 'extra' => ['meta' => Dsl::obj(['actions' => 'object', 'logs' => 'ServiceLog[]', 'usage' => 'UsageSummary'])]]],
            'admin.services.update' => ['Update label, notes, price, billing cycle, due date or server', self::one('Service'), 'ServiceUpdateInput'],
            'admin.services.usage.index' => ['Current-period usage summary with the estimated charge (summary) and the paginated usage records, newest first', self::page('UsageRecord') + ['extra' => ['summary' => 'UsageSummary']], null, [
                'meter' => ['string', 'Meter key'], 'from' => ['date', 'Recorded on or after'], 'to' => ['date', 'Recorded on or before'], 'invoiced' => 'boolean', 'source' => 'string',
            ]],
            'admin.services.usage.store' => ['Record usage for a service. Idempotent per (service, meter, idempotency_key): 201 for a new record, 200 with the original on a repeat. 422 for an unknown meter, a negative quantity or a service that is not active or suspended. Fires usage.recorded', self::created('UsageRecord') + ['errors' => ['200' => 'Repeat of an earlier idempotency_key: the original record, nothing changed']], Dsl::obj([
                'meter!' => ['string', 'Meter key of the service\'s product'], 'quantity!' => ['number', 'Decimal, 0 or more, up to 6 decimals'], 'recorded_at' => ['datetime', 'Defaults to now'],
                'idempotency_key' => ['string', 'Up to 191 characters'], 'source' => ['string', 'Default "api"'], 'metadata' => 'object',
            ])],
            'admin.services.usage.destroy' => ['Delete a usage record that has not been invoiced yet (422 once it has)', self::none()],
            'admin.services.logs' => ['Provisioning log of a service', self::page('ServiceLog')],
            'admin.services.activate' => ['Provision and activate a service', self::one('Service')],
            'admin.services.suspend' => ['Suspend a service', self::one('Service'), Dsl::obj(['reason' => 'string'])],
            'admin.services.unsuspend' => ['Unsuspend a service', self::one('Service')],
            'admin.services.terminate' => ['Terminate a service (deletes the remote account)', self::one('Service')],
            'admin.services.cancel' => ['Cancel a service (at period end unless immediately)', self::one('Service'), Dsl::obj(['immediately' => 'boolean', 'reason' => 'string'])],
            'admin.services.reveal' => ['Reveal the secrets a module stored for a service (logged)', self::one('ServiceSecrets')],
            'admin.services.action' => ['Run a module admin action', self::one('ActionResult')],

            // ---- admin: products, groups, modules -----------------------------------------------------------------
            'admin.products.index' => ['List products, including inactive and hidden ones', self::page('Product'), null, ['is_active' => 'boolean', 'product_group_id' => 'integer', 'q' => ['string', 'Name']]],
            'admin.products.store' => ['Create a product', self::created('Product'), 'ProductInput'],
            'admin.products.reorder' => ['Set the display order of products', self::many('Product'), 'ReorderInput'],
            'admin.products.show' => ['Get a product', self::one('Product')],
            'admin.products.update' => ['Update a product', self::one('Product'), 'ProductInput'],
            'admin.products.destroy' => ['Delete a product (administrators only; not while it has live services)', self::none()],
            'admin.products.meters.index' => ['List the usage meters of a product', self::many('UsageMeter')],
            'admin.products.meters.store' => ['Add a usage meter to a product. Prices are integers in micro-minor units (0.002 USD = 200000)', self::created('UsageMeter'), 'UsageMeterInput'],
            'admin.products.meters.show' => ['Get a usage meter', self::one('UsageMeter')],
            'admin.products.meters.update' => ['Update a usage meter (partial; the key cannot change; sending prices replaces all of its prices)', self::one('UsageMeter'), 'UsageMeterUpdateInput'],
            'admin.products.meters.destroy' => ['Delete a usage meter and its records. 422 when any of its usage was already invoiced', self::none()],
            'admin.product-groups.index' => ['List product groups', self::page('ProductGroup')],
            'admin.product-groups.store' => ['Create a product group', self::created('ProductGroup'), 'ProductGroupInput'],
            'admin.product-groups.reorder' => ['Set the display order of product groups', self::many('ProductGroup'), 'ReorderInput'],
            'admin.product-groups.show' => ['Get a product group', self::one('ProductGroup')],
            'admin.product-groups.update' => ['Update a product group', self::one('ProductGroup'), 'ProductGroupInput'],
            'admin.product-groups.destroy' => ['Delete a product group (administrators only; its products become ungrouped)', self::none()],
            'admin.modules.index' => ['Provisioning modules of enabled plugins, with product and server field definitions', self::many('Module')],

            // ---- admin: servers -----------------------------------------------------------------------------------
            'admin.servers.index' => ['List provisioning servers', self::page('Server'), null, ['module' => 'string', 'is_active' => 'boolean']],
            'admin.servers.store' => ['Add a provisioning server', self::created('Server'), 'ServerInput'],
            'admin.servers.show' => ['Get a server (the secret is never returned)', self::one('Server')],
            'admin.servers.update' => ['Update a server (partial; a blank secret keeps the stored one)', self::one('Server'), 'ServerInput'],
            'admin.servers.destroy' => ['Delete a server (administrators only; not while it hosts services)', self::none()],
            'admin.servers.test' => ['Test the connection to a server', self::one('ActionResult')],

            // ---- admin: invoices ----------------------------------------------------------------------------------
            'admin.invoices.index' => ['List invoices', self::page('Invoice'), null, [
                'status' => 'draft|open|paid|void|uncollectible', 'client_id' => 'integer', 'q' => ['string', 'Number, reference or client'], 'from' => ['date', 'Issued on or after'], 'to' => ['date', 'Issued on or before'], 'overdue' => 'boolean',
            ]],
            'admin.invoices.pdf' => ['Download the invoice as a PDF, drafts included (attachment; ?inline=1 to display)', self::file()],
            'admin.invoices.summary' => ['Outstanding and overdue totals per currency', self::one('InvoiceSummary')],
            'admin.invoices.store' => ['Create an invoice', self::created('Invoice'), 'InvoiceInput'],
            'admin.invoices.show' => ['Get an invoice. pay_url is the public link to share with the client', self::one('Invoice')],
            'admin.invoices.update' => ['Edit a draft invoice', self::one('Invoice'), 'InvoiceInput'],
            'admin.invoices.destroy' => ['Delete a draft invoice', self::none()],
            'admin.invoices.issue' => ['Issue a draft invoice (assigns a number, emails the client)', self::one('Invoice'), Dsl::obj(['send' => ['boolean', 'Email the client (default true)']])],
            'admin.invoices.resend' => ['Email an open invoice to the client again', self::one('Invoice')],
            'admin.invoices.void' => ['Void an unpaid invoice', self::one('Invoice')],
            'admin.invoices.uncollectible' => ['Mark an open invoice uncollectible', self::one('Invoice')],
            'admin.invoices.payments.store' => ['Record a manual payment (bank transfer, cash, cheque)', self::created('Payment'), 'ManualPaymentInput'],

            // ---- admin: payments ----------------------------------------------------------------------------------
            'admin.payments.index' => ['List payments and refunds', self::page('Payment'), null, [
                'invoice_id' => 'integer', 'client_id' => 'integer', 'gateway' => 'string', 'type' => 'payment|refund', 'status' => 'completed|pending|failed', 'from' => ['date', 'Paid on or after'], 'to' => ['date', 'Paid on or before'],
            ]],
            'admin.payments.summary' => ['Received, refunded and net totals per currency (same filters as the list)', self::many('PaymentSummary'), null, [
                'invoice_id' => 'integer', 'client_id' => 'integer', 'gateway' => 'string', 'type' => 'payment|refund', 'from' => 'date', 'to' => 'date',
            ]],
            'admin.payments.show' => ['Get a payment', self::one('Payment')],
            'admin.payments.refund' => ['Refund (part of) a payment through its gateway', self::created('Payment'), 'RefundInput'],

            // ---- admin: quotes ------------------------------------------------------------------------------------
            'admin.quotes.index' => ['List quotes', self::page('Quote'), null, ['status' => 'draft|sent|accepted|declined|expired|converted', 'client_id' => 'integer', 'q' => ['string', 'Number, title or client']]],
            'admin.quotes.store' => ['Create a quote', self::created('Quote'), 'QuoteInput'],
            'admin.quotes.show' => ['Get a quote. view_url is the public link to share with the client', self::one('Quote')],
            'admin.quotes.update' => ['Edit a draft quote', self::one('Quote'), 'QuoteInput'],
            'admin.quotes.destroy' => ['Delete a draft quote', self::none()],
            'admin.quotes.pdf' => ['Download the quote as a PDF, drafts included (attachment; ?inline=1 to display)', self::file()],
            'admin.quotes.send' => ['Send a quote to the client', self::one('Quote')],
            'admin.quotes.convert' => ['Convert a quote into an invoice (and pending services for product lines)', self::created('Invoice')],
            'admin.quotes.accept' => ['Record acceptance of a sent quote on the client\'s behalf', self::one('Quote')],
            'admin.quotes.decline' => ['Record a decline', self::one('Quote')],
            'admin.quotes.duplicate' => ['Copy a quote into a new draft', self::created('Quote')],

            // ---- admin: tax rates ---------------------------------------------------------------------------------
            'admin.tax-rates.index' => ['List tax rates', self::page('TaxRate')],
            'admin.tax-rates.store' => ['Create a tax rate', self::created('TaxRate'), Dsl::obj(['name!' => 'string', 'rate!' => ['number', 'Percent, 0–100'], 'country' => ['string', 'ISO 3166-1 alpha-2'], 'region' => 'string', 'is_active' => 'boolean'])],
            'admin.tax-rates.show' => ['Get a tax rate', self::one('TaxRate')],
            'admin.tax-rates.update' => ['Update a tax rate', self::one('TaxRate'), Dsl::obj(['name' => 'string', 'rate' => 'number', 'country' => 'string', 'region' => 'string', 'is_active' => 'boolean'])],
            'admin.tax-rates.destroy' => ['Delete a tax rate (administrators only)', self::none()],

            // ---- admin: settings ----------------------------------------------------------------------------------
            'admin.settings.index' => ['List the settings sections: general, billing, email, autopay (the automatic payment group of billing), security (two-factor requirement for staff), payments, system (administrators only)', self::many('SettingsSectionInfo')],
            'admin.settings.show' => ['Read a settings section: fields with current values; secrets only report has_value (administrators only)', self::one('SettingsSection')],
            'admin.settings.update' => ['Update general, billing, email, autopay or security settings ({"settings": {...}}) or payment gateways ({"gateways": {...}}); partial, validated like the admin screen (administrators only)', self::one('SettingsSection'), ['oneOf' => [Dsl::ref('SettingsInput'), Dsl::ref('GatewaySettingsInput')]]],
            'admin.settings.email.test' => ['Send a test email to the calling administrator (administrators only)', self::one('ActionResult')],
            'admin.settings.cron-token' => ['Generate a new cron URL token, returned once (administrators only)', self::one('CronToken')],
            'admin.settings.run' => ['Run the daily billing tasks now (administrators only)', self::one('BillingRunResult')],

            // ---- admin: plugins -----------------------------------------------------------------------------------
            'admin.plugins.index' => ['Installed plugins and their state', self::many('Plugin')],
            'admin.plugins.show' => ['Get a plugin', self::one('Plugin')],
            'admin.plugins.enable' => ['Enable a plugin (administrators only)', self::one('Plugin')],
            'admin.plugins.disable' => ['Disable a plugin; its data is kept (administrators only)', self::one('Plugin')],
            'admin.plugins.settings' => ['Read the settings of an enabled plugin; secrets only report has_value', self::one('PluginSettings')],
            'admin.plugins.settings.update' => ['Update plugin settings (partial; administrators only)', self::one('PluginSettings'), 'PluginSettingsInput'],

            // ---- admin: API tokens --------------------------------------------------------------------------------
            'admin.api-tokens.index' => ['List API tokens: your own, or with user_id / all=1 those of other users (administrators only)', self::page('ApiToken'), null, ['user_id' => 'integer', 'all' => 'boolean']],
            'admin.api-tokens.store' => ['Create an API token for yourself (administrators only). The token is returned once', self::created('ApiTokenCreated'), 'ApiTokenInput'],
            'admin.api-tokens.show' => ['Get an API token (without its value)', self::one('ApiToken')],
            'admin.api-tokens.destroy' => ['Revoke any API token (administrators only)', self::none()],

            // ---- admin: webhooks and catalogues -------------------------------------------------------------------
            'admin.webhooks.index' => ['List webhook endpoints', self::page('Webhook')],
            'admin.webhooks.store' => ['Create a webhook endpoint (administrators only); the signing secret is returned once', self::created('WebhookCreated'), Dsl::obj(['url!' => 'uri', 'description' => 'string', 'events' => ['string[]', 'Event names (GET /admin/events); omit for all'], 'is_active' => 'boolean'])],
            'admin.webhooks.show' => ['Get a webhook endpoint', self::one('Webhook')],
            'admin.webhooks.update' => ['Update a webhook endpoint (administrators only)', self::one('Webhook'), Dsl::obj(['url' => 'uri', 'description' => 'string', 'events' => 'string[]', 'is_active' => 'boolean'])],
            'admin.webhooks.destroy' => ['Delete a webhook endpoint (administrators only)', self::none()],
            'admin.webhooks.test' => ['Send a ping event now', ['kind' => 'one', 'schema' => 'WebhookDelivery', 'status' => 202]],
            'admin.webhooks.deliveries' => ['Delivery log of a webhook endpoint', self::page('WebhookDelivery')],
            'admin.events.index' => ['Webhook event catalogue', self::many('EventInfo')],
            'admin.me' => ['The staff user behind this token and the token\'s abilities', self::one('StaffMe')],
            'admin.abilities.index' => ['All valid token abilities, for customers and for staff', self::one('Abilities')],
        ];
    }

    /** @param  string|array<string, mixed>  $schema @return array<string, mixed> */
    public static function one(string|array $schema): array
    {
        return ['kind' => 'one', 'schema' => $schema];
    }

    /** Paginated list: {data, links, meta}, with ?page and ?per_page. @param  string|array<string, mixed>  $schema */
    public static function page(string|array $schema): array
    {
        return ['kind' => 'page', 'schema' => $schema];
    }

    /** Plain list without pagination: {data: [...]}. @param  string|array<string, mixed>  $schema */
    public static function many(string|array $schema): array
    {
        return ['kind' => 'many', 'schema' => $schema];
    }

    /** @param  string|array<string, mixed>  $schema */
    public static function created(string|array $schema): array
    {
        return ['kind' => 'one', 'schema' => $schema, 'status' => 201];
    }

    /** The body is the schema itself, without the {data} envelope. @param  string|array<string, mixed>  $schema */
    public static function raw(string|array $schema): array
    {
        return ['kind' => 'raw', 'schema' => $schema];
    }

    /** A file download. @param  string  $media  Content-Type of the response */
    public static function file(string $media = 'application/pdf'): array
    {
        return ['kind' => 'file', 'media' => $media];
    }

    public static function none(): array
    {
        return ['kind' => 'none', 'status' => 204];
    }
}
