# Tidemark Billing REST API (v1)

Base URL: `https://your-domain/api/v1`. The full machine-readable spec is served at `GET /api/v1/openapi.json` (OpenAPI 3.1).

## Authentication

Every request except the public ones sends `Authorization: Bearer <token>`.

```bash
curl -X POST https://billing.example.com/api/v1/auth/token \
  -H 'Content-Type: application/json' \
  -d '{"email":"jane@example.com","password":"secret","name":"My storefront","abilities":["invoices.read","services.read"]}'
```

The response contains the token **once** (`data.token`); tokens are stored hashed. `abilities` is optional: omit it for a token that can do everything its owner can. Revoke with `DELETE /auth/token`. `POST /auth/register` creates a client account and returns a token (when registration is open).

### Two-factor authentication

Accounts that turned on two-factor authentication must also send `otp` in `POST /auth/token`: the 6-digit authenticator code, or one unused recovery code. Send it **as a string** (`"012345"`, not `12345`).

```bash
curl -X POST $API/auth/token -H 'Content-Type: application/json' \
  -d '{"email":"jane@example.com","password":"secret","otp":"123456"}'
```

| Situation | Response |
| --- | --- |
| No `otp` sent | `401` `otp_required` (nothing is counted against the attempt limit) |
| Wrong, replayed (a code works once per 30-second step) or already used recovery code | `401` `invalid_otp` |
| 5 wrong codes in 5 minutes for this user and IP | `429` `too_many_requests` with `Retry-After` (the limit applies even to a correct code until it expires) |
| Staff without 2FA while **Settings → Security → Require two-factor authentication for staff** is on | `403` `two_factor_enrolment_required`: set it up in the admin area first (`/admin/account/security`); enrolment itself is not available through the API. This applies to **every** call made with such a user's token, also tokens issued before the setting was turned on, and to creating tokens |
| Wrong password | `401` `invalid_credentials`, whether or not the account has 2FA |

A recovery code is spent and logged (`two_factor.recovery_code_used`). The API never sets or honours the browser's "remember this device" cookie, and the code is asked only at token issue: an issued token keeps working until it expires or is revoked, so use `expires_in_days` for short-lived integrations. The one exception is the staff requirement above, which is checked on every request. An administrator resetting a staff member's 2FA can revoke that user's tokens at the same time (`revoke_api_tokens`); a plain reset and turning 2FA off yourself keep them. Customers whose staff "view as customer" token comes from `/admin/users/{id}/impersonate` never need the customer's code. The `security` settings section (`GET/PUT /admin/settings/security`) turns the staff requirement on and off.

Two audiences, decided by the token owner's role:

- **Customer tokens** use the client endpoints (`/me`, `/services`, `/invoices`, `/quotes`, `/payments`, `/orders`). Everything is scoped to the owner's client; other clients' records return `404`.
- **Staff tokens** (admin/staff users) use `/admin/*`. Staff tokens cannot call the client endpoints and customer tokens cannot call `/admin/*` (`403`). Deleting clients, products and tax rates needs an administrator.

Public (no token): `GET /products`, `GET /products/{id-or-slug}`, `GET /openapi.json`, `POST /auth/token`, `POST /auth/register`.

### Abilities

A token without an ability list can do everything its owner can; a token with a list can only do what is listed (`403 forbidden` otherwise). The single list of valid abilities is `App\Support\Abilities` (also served by `GET /admin/abilities`); unknown abilities are rejected with `422` when a token is created. Abilities follow `<resource>.read` for `GET` and `<resource>.write` for changes.

Customer tokens: `profile.read`, `profile.write`, `orders.write`, `services.read`, `services.write`, `invoices.read` (also invoice PDFs), `invoices.pay` (gateway checkout and paying with a saved method), `quotes.read` (also quote PDFs), `quotes.write`, `payment-methods.read`, `payment-methods.write`, `usage.read`, `tickets.read` (tickets, replies, attachment downloads, the department list), `tickets.write` (open, reply, close).

Staff tokens:

| Ability | Covers |
| --- | --- |
| `dashboard.read` | dashboard statistics |
| `clients.read` / `clients.write` | clients, their portal logins (`/users/{id}`), tax-rate lookup; create/update/delete clients, reset passwords, deactivate logins |
| `products.read` / `products.write` | products, product groups, provisioning modules; ordering and deletes |
| `services.read` / `services.write` | services and their logs; create, lifecycle actions, module actions, `reveal` (decrypts stored secrets, logged) |
| `invoices.read` / `invoices.write` | invoices and their PDFs; create, edit drafts, issue, resend, void, uncollectible, record manual payments |
| `quotes.read` / `quotes.write` | quotes and their PDFs; create, edit, send, convert, accept/decline, duplicate |
| `payments.read` / `payments.write` | payments and totals; refunds |
| `tax-rates.read` / `tax-rates.write` | tax rates |
| `servers.read` / `servers.write` | provisioning servers; create, update, test connection, delete |
| `settings.read` / `settings.write` | settings (secrets masked); change settings, test email, cron token, run billing |
| `plugins.read` / `plugins.write` | plugins and their settings; enable, disable, configure |
| `webhooks.read` / `webhooks.write` | webhook endpoints, deliveries, event catalogue |
| `api-tokens.read` / `api-tokens.write` | list and create/revoke API tokens |
| `payment-methods.read` / `payment-methods.write` | a client's saved payment methods (never the gateway tokens); remove one |
| `autopay.read` | the log of automatic payment attempts (the autopay settings need `settings.read`/`settings.write` and an administrator) |
| `usage.read` / `usage.write` | product meters, service usage and usage records; create/change/delete meters, record and delete usage |
| `activity.read` | the activity log |
| `staff-users.read` / `staff-users.write` | staff accounts (administrators only): list, create, change role, activate/deactivate, password links, 2FA reset |
| `tickets.read` / `tickets.write` | support tickets visible to the token's user, with internal notes and attachments; open for clients, reply, note, status, priority, custom fields, assign, claim, lock, transfer, close, reopen; delete (administrators only) |
| `support.read` / `support.write` | support departments and ticket custom fields; create, change, delete (administrators only) |
| `impersonation.write` | issue short-lived customer tokens |

Some actions need an **administrator** on top of the ability, exactly like the admin screens: deleting clients, products, product groups, servers and tax rates; everything under settings, API tokens and plugin enable/disable/configure; creating, changing and deleting webhooks; everything under staff users. A staff (non-admin) token gets `403` there.

## Conventions

- JSON in, JSON out. Money is `{"amount": 1500, "currency": "USD", "formatted": "$15.00"}` with `amount` in minor units. Send amounts to write endpoints as integers in minor units.
- Records carry a numeric `id` and, for services, invoices, quotes, payments and clients, a `uuid`. Client endpoints address services/invoices/quotes by `uuid`; admin endpoints by numeric `id`.
- Dates are ISO 8601 (`2026-11-09` or `2026-11-09T10:00:00+00:00`).
- Lists are paginated: `?page=2&per_page=50` (max 100) returning `{"data": [...], "links": {...}, "meta": {...}}`. Filters are query parameters (`status`, `client_id`, `q`, ...), see the spec.
- Filters are validated like the admin screens: an unknown `status` is a `422`, not an empty list. Date filters (`from`, `to`) are inclusive `YYYY-MM-DD`.
- Every change made through the admin API is written to the activity log (`GET /admin/activity`) with `properties.via = "api"`.
- Secrets are **write-only**: server secrets and module passwords (also in a product's `module_config`), gateway keys, the SMTP password, plugin secrets, webhook signing secrets and token values are never returned by any read endpoint. Reads say whether one is stored (`has_secret`, `has_value`); API tokens and webhook secrets are shown once, when created.
- Rate limits: 120 requests/minute per token, 10/minute per IP on `/auth/*`. `429` includes `Retry-After`.

### Errors

```json
{"error": {"code": "validation_failed", "message": "The given data was invalid.", "details": {"email": ["The email field is required."]}}}
```

Codes: `validation_failed` (422), `domain_error` (422, a business rule such as "only draft invoices can be edited"), `action_failed` (422, a service module action reported failure), `unauthenticated` (401), `invalid_credentials` (401), `forbidden` (403), `not_found` (404), `too_many_requests` (429), `server_error` (500).

## Paying an invoice from your own frontend

1. `POST /orders {"product_id": 1, "billing_cycle": "monthly"}` returns the pending `service`, its `invoice` and a `pay_url`.
2. `GET /gateways` lists payment methods; `POST /invoices/{uuid}/pay {"gateway": "stripe"}` returns `checkout_url`. Redirect the customer there.
3. The gateway confirms by webhook; the invoice becomes `paid` and the service is provisioned. Poll `GET /services/{uuid}` or subscribe to a webhook.

## Saved payment methods and automatic payment

Customers (`payment-methods.read` / `payment-methods.write`; paying needs `invoices.pay`):

1. `GET /payment-methods` lists the active methods, default first (`display_name`, `brand`, `last4`, `expiry`, `is_default`, `autopay`, ...) and `meta.gateways`, the providers that can save one. Provider tokens and gateway data are never returned.
2. `POST /payment-methods/setup {"gateway": "stripe", "autopay": true}` returns `data.redirect_url`, the provider's hosted setup page. Send the customer there in a browser: the provider returns them to the client area, which stores the method (they may have to sign in to the client area once). Poll `GET /payment-methods` afterwards.
3. `PATCH /payment-methods/{uuid} {"is_default": true, "autopay": true}` (either field; only `true` is accepted for `is_default`) makes it the default and/or turns automatic payment on or off for it.
4. `DELETE /payment-methods/{uuid}` removes it at the provider and locally: `{"data": {"removed": true, "gateway_confirmed": true}}` (`false` when the provider could not confirm; it is removed here either way).
5. `POST /invoices/{uuid}/pay-saved {"payment_method": "<uuid>"}` charges the invoice balance, through the same code as the client area. `data.status` is `succeeded` (the invoice is paid), `pending` (a bank debit that settles later) or `requires_action` (the bank wants the customer to authenticate: use `POST /invoices/{uuid}/pay` and the checkout URL instead). A decline, an expired method or an unpayable invoice is a `422` (`payment_failed` or `domain_error`). The invoice and the method must both belong to the token's client (`404` otherwise).

Staff: `GET /admin/clients/{id}/payment-methods`, `DELETE /admin/clients/{id}/payment-methods/{uuid}` (staff cannot add or charge methods), `GET /admin/autopay/attempts` (filters `status`, `invoice_id`, `client_id`, `gateway`, `from`, `to`). The automatic payment settings (`autopay.enabled`, `days_before_due`, `renewals_at_creation`, `retry_days`, `max_attempts`) are a settings section: `GET/PUT /admin/settings/autopay {"settings": {"autopay.max_attempts": 5}}` (administrators only, validated like the billing screen, where the same fields also appear).

## Usage billing (metered products)

Products can have meters; usage is recorded against services and billed in arrears on the renewal invoice. Prices are **integers in micro-minor units** (1 = one millionth of the currency's minor unit, so `$0.002` = `200000`); quantities are decimal strings with up to six decimals.

Staff:

- Meters: `GET/POST /admin/products/{id}/meters`, `GET/PATCH/DELETE /admin/products/{id}/meters/{meterId}` (`usage.read` / `usage.write`). A meter is `{key, name, unit, aggregation: sum|max|last, pricing_model: per_unit|tiered|package, included_quantity, package_size, prices: [{currency, unit_price}] }`; tiered prices send `tiers: [{up_to, unit_price, flat_fee}]` (last tier `up_to: null`). The key cannot change; sending `prices` replaces them all. Deleting a meter deletes its unbilled usage and is refused (`422`) once any of it was invoiced. Meters are also listed in `meters` of every product (admin and public catalogue).
- Record: `POST /admin/services/{id}/usage {"meter": "api_calls", "quantity": 120, "recorded_at": "2026-11-09T10:00:00Z", "idempotency_key": "job-8841", "source": "myapp"}` returns `201` with the record, or `200` with the **original** record (nothing changes, no event) when the same `idempotency_key` was used before for that service and meter. `422` for an unknown meter, a negative or malformed quantity, or a service that is not active or suspended. Every new record fires the `usage.recorded` webhook event.
- Read: `GET /admin/services/{id}/usage` returns the paginated records, newest first (filters `meter` (key), `from`, `to`, `invoiced`, `source`) plus `summary`: the current period's unbilled usage per meter and the estimated charge before tax. `GET /admin/services/{id}` carries the same summary in `meta.usage` for metered services.
- Delete: `DELETE /admin/services/{id}/usage/{recordId}` removes a record that has not been invoiced (`422` after).

Customers (`usage.read`): `GET /services/{uuid}/usage` is a read-only aggregated summary for their own service (also `meta.usage` of `GET /services/{uuid}`). It never lists raw records or their metadata.

## PDFs

`GET /invoices/{uuid}/pdf` and `GET /quotes/{uuid}/pdf` (customer tokens, own non-draft documents only, `invoices.read` / `quotes.read`) and `GET /admin/invoices/{id}/pdf`, `GET /admin/quotes/{id}/pdf` (staff, drafts included) return `application/pdf` with `Content-Disposition: attachment; filename="INV-00012.pdf"`; add `?inline=1` to display instead of download. Errors are still JSON.

## Outbound webhooks

Create endpoints with `POST /admin/webhooks {"url": "...", "events": ["invoice.paid"]}` (omit `events` for all). The response includes the signing `secret` **once**. Each delivery is a `POST` with JSON `{"id","event","created_at","data"}`, where `data` has the same shape as the API resource (e.g. an invoice). Failed deliveries retry with backoff; see `GET /admin/webhooks/{id}/deliveries`, and `POST /admin/webhooks/{id}/test` sends a `ping`.

Headers: `X-Billing-Event`, `X-Billing-Delivery`, `X-Billing-Timestamp`, `X-Billing-Signature: sha256=<hex>` where the signature is `HMAC-SHA256(secret, timestamp + "." + rawBody)`. Reject old timestamps (e.g. > 5 minutes) to prevent replays.

PHP:

```php
$raw = file_get_contents('php://input');
$expected = 'sha256='.hash_hmac('sha256', $_SERVER['HTTP_X_BILLING_TIMESTAMP'].'.'.$raw, $secret);
if (! hash_equals($expected, $_SERVER['HTTP_X_BILLING_SIGNATURE'] ?? '')) { http_response_code(400); exit; }
```

Node (Express, with `express.raw({type: 'application/json'})`):

```js
const crypto = require('crypto');
function verify(req, secret) {
  const expected = 'sha256=' + crypto.createHmac('sha256', secret)
    .update(req.headers['x-billing-timestamp'] + '.' + req.body.toString('utf8')).digest('hex');
  const given = Buffer.from(req.headers['x-billing-signature'] || '');
  return given.length === expected.length && crypto.timingSafeEqual(given, Buffer.from(expected));
}
```

### Events

`client.created`, `invoice.created`, `invoice.paid`, `invoice.voided`, `payment.received`, `payment.refunded`, `quote.sent`, `quote.accepted`, `quote.declined`, `service.created`, `service.activated`, `service.suspended`, `service.unsuspended`, `service.cancelled`, `service.terminated`, `service.provisioning_failed`, `service.trial_started`, `service.trial_ending`, `service.trial_converted`, `service.plan_changed`, `service.plan_change_scheduled`, `usage.recorded`, `ticket.created`, `ticket.replied`, `ticket.status_changed`, `ticket.assigned`. Plugins may add more; `GET /admin/events` returns the live catalogue.

## Support tickets

Support tickets are optional: while "Enable support tickets" is off (Settings → Support), every endpoint below answers `404` (after authentication and the ability check). Abilities: `tickets.read` / `tickets.write` for customers and staff, plus `support.read` / `support.write` for departments and custom fields (staff).

### Customers (client API)

| Endpoint | |
| --- | --- |
| `GET /ticket-departments` | departments listed to customers (active and public) with `fields`, the custom fields to show on the form. Staff-only fields are never included. `tickets.read` |
| `GET /tickets` | your client's tickets, newest first; filter `status`. `tickets.read` |
| `POST /tickets` | `department_id` (a listed department), `subject`, `message`, optional `service` (uuid of one of your services), `custom` (`{"key": "value"}`), attachments. `tickets.write` |
| `GET /tickets/{uuid}` | the ticket with its `messages`: replies only. `tickets.read` |
| `POST /tickets/{uuid}/replies` | `message` and/or attachments. A reply moves the ticket to `customer_reply` (and reopens a closed one). `tickets.write` |
| `POST /tickets/{uuid}/close` | close your ticket. `tickets.write` |
| `GET /tickets/{uuid}/attachments/{uuid}` | download an attachment of a reply. `tickets.read` |

Customers only reach tickets of their own client (`404` otherwise). They never receive internal notes, system lines, the files attached to notes, or staff-only custom fields; sending a `custom` key that is not a customer-visible field of the department is a `422`. `custom` values are validated by type (text, textarea, select, checkbox, number, date) and required fields are enforced. Opening a ticket in an unlisted or inactive department is a `422`. A **locked** ticket cannot be replied to or closed by the customer (`422 domain_error`; `can_reply` says so up front).

```bash
curl -X POST $API/tickets -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"department_id": 1, "subject": "Site is down", "message": "Since this morning...", "custom": {"domain": "example.com"}}'
```

### Attachments

Send files either as `multipart/form-data` (`files[]`, plus the other fields as form fields) or, for JSON-only clients, as `"attachments": [{"filename": "log.txt", "content_base64": "..."}]`. Both go through the same checks, and a single bad file refuses the whole request with `422` (`details.attachments`), nothing being stored:

- at most `support.max_attachments` files (default 5) of at most `support.max_attachment_mb` MB each (default 10), non-empty; JSON files must be valid base64;
- executables, scripts, web-page types (html, svg, xml) and dangerous extensions anywhere in the name (`x.php.jpg` too) are refused, judged by the file's real content as well as its name; Settings → Support can narrow the list further with an allow-list of extensions;
- the type is detected from the content, never taken from the client; files are stored privately under random names and the original name is cleaned.

Downloads are authenticated API calls (`download_url` in each attachment): always `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, a sandboxing `Content-Security-Policy`, `Cache-Control: private, no-store`; only safe types (png, jpeg, gif, webp, pdf, plain text) keep their own `Content-Type`, everything else is `application/octet-stream`.

### Staff (admin API)

Staff tokens see the tickets of the departments their user works in plus tickets assigned to them; administrators see everything. A ticket outside that is a `404`. Staff members' departments are set with `department_ids` on `POST/PATCH /admin/staff-users` (returned on every staff user), or with `staff_ids` on a department.

| Endpoint | |
| --- | --- |
| `GET /admin/tickets` | filters `status` (comma list), `priority`, `department_id`, `assigned_to`, `client_id`, `q` (number, subject, requester), `mine=1`, `unassigned=1`; paginated. `tickets.read` |
| `POST /admin/tickets` | open for a client: `client_id`, `department_id`, `subject`, `message`, optional `user_id` (a login of that client), `service_id`, `priority`, `custom` (staff-only fields allowed), `notify`, attachments. You must work in the department. The ticket starts as `answered` |
| `GET /admin/tickets/{id}` | all `messages` (replies, notes with `kind: note`, system lines with `kind: event`), attachment metadata, custom fields |
| `PATCH /admin/tickets/{id}` | `status`, `priority`, `custom_fields` (partial; `null` clears a field) |
| `POST /admin/tickets/{id}/replies` | reply to the customer (emailed); optional `status` afterwards (default `answered`), attachments |
| `POST /admin/tickets/{id}/notes` | internal note, attachments |
| `POST /admin/tickets/{id}/assign` | `{"user_id": 5}` or `{"user_id": null}`; the assignee must be an administrator or work in the department (`422`) |
| `POST /admin/tickets/{id}/claim`, `/lock`, `/unlock`, `/close`, `/reopen` | claim for yourself; lock (customer cannot reply or close); unlock; close; reopen (`422` when not closed) |
| `POST /admin/tickets/{id}/transfer` | `{"department_id": 2}`; an assignee who is not in the new department is cleared |
| `DELETE /admin/tickets/{id}` | administrators only |
| `GET /admin/tickets/{id}/attachments/{uuid}` | download any attachment, note files included |
| `/admin/ticket-departments` | `GET` (`support.read`, any staff token), `POST/PATCH/DELETE` (`support.write`, administrators only). Fields: `name`, `slug`, `description`, `sort`, `is_public` (listed to customers), `is_active`, `email`, `auto_reply`, `imap_enabled`, `imap_host`, `imap_port`, `imap_encryption` (`ssl`\|`starttls`\|`none`), `imap_validate_cert`, `imap_username`, `imap_password`, `imap_folder`, `imap_after` (`seen`\|`delete`), `staff_ids` (replaces the staff list). A department with tickets cannot be deleted (`422`) |
| `/admin/ticket-fields` | `GET` (`support.read`), `POST/PATCH/DELETE` (`support.write`, administrators only). Fields: `key` (create only), `label`, `type` (`text`, `textarea`, `select`, `checkbox`, `number`, `date`), `department_id` (null: every department), `options` (select), `required`, `staff_only`, `help`, `sort`, `is_active` |

The mailbox **password is write-only**: it is accepted as `imap_password` (blank keeps the stored one, `clear_imap_password: true` removes it) and never returned; responses carry `has_imap_password`. Mailbox connection details (`imap_*`, `staff_ids`) are only included when the token belongs to an administrator.

Webhooks: `ticket.created`, `ticket.replied`, `ticket.status_changed`, `ticket.assigned` (see [Outbound webhooks](#outbound-webhooks)); internal notes never trigger one.

## Trials and plan changes

**Trials.** A product with `trial_days > 0` starts a free trial when a client orders it for the first time (once per client per product). Customer `POST /orders` and admin `POST /admin/services` then return `201` with the provisioned `service` and `"invoice": null` (and `"pay_url": null` for customers); the first invoice is raised when the trial ends. If the product has `trial_requires_payment_method` and the client has no saved payment method the order is a `422` with the reason. Admin orders accept `"trial": false` to bill straight away. The public catalogue shows `trial_days`, and `trial_available` when a customer token is sent (`null` without one). Services expose `on_trial`, `trial_ends_at`, `trial_converted_at`.

Admin (ability `services.write`): `POST /admin/services/{id}/trial/extend {"days": 7}` moves the end of a running trial and its first due date; `POST /admin/services/{id}/trial/end` raises the first invoice now. Both are `422` when the service is not on a trial.

**Plan changes (prorated).** Services carry `scheduled_change` (`{id, to_product, to_cycle, to_amount, effective_date}` or `null`), and customers also see `can_change_plan`. Upgrades are credited for the unused period and invoiced now; downgrades take effect at the next renewal by default. A credit larger than the charge is not refunded or carried forward (`forfeited` in the preview).

| | Admin (staff token) | Client (customer token) |
| --- | --- | --- |
| Options | `GET /admin/services/{id}/plan-options` (any active product) | `GET /services/{uuid}/plan-options` (same group, visible products; empty with `can_change_plan: false` when disabled) |
| Preview | `POST .../plan-change/preview {product_id, billing_cycle, timing?}` | same |
| Change | `POST .../plan-change` (source `api`; `timing`: `immediate` or `renewal`) | `POST .../plan-change` (source `customer`; downgrades are always at renewal) |
| Cancel scheduled | `DELETE .../plan-change` | `DELETE .../plan-change` |
| History | `GET /admin/services/{id}/plan-changes` | n/a |

Abilities: options and preview need `services.read`; changing and cancelling need `services.write`. Customers only reach their own services (`404` otherwise); a plan that is not offered, a disabled feature or a service that cannot change plan is a `422`.

**Override versus plan change.** `PATCH /admin/services/{id}` with `amount` or `billing_cycle` is the override path: it writes exactly what you send, with no proration, no invoice and no events, then re-runs the module's package change. Use it to correct a price or grandfather a customer. `POST /admin/services/{id}/plan-change` is the billing-aware path: it prorates, raises the invoice, schedules downgrades and fires `service.plan_changed` / `service.plan_change_scheduled`.

## Admin API reference

Everything the admin screens can do is available under `/api/v1/admin` with a staff token. The generated reference with every field is `GET /api/v1/openapi.json`; this is the map.

| Area | Endpoints |
| --- | --- |
| Dashboard | `GET /dashboard` (revenue this month, outstanding, overdue count, active services, new clients, 12-month revenue, recent invoices/payments, services needing attention, failed webhooks) |
| Clients | `GET/POST /clients`, `GET/PATCH/DELETE /clients/{id}` (status and internal notes are plain fields; delete is refused while the client has live services or unpaid invoices), `GET /clients/{id}/tax-rate`; related records via filters: `/services?client_id=`, `/invoices?client_id=`, `/quotes?client_id=`, `/payments?client_id=` |
| Staff accounts (administrators only; abilities `staff-users.read` / `.write`) | `GET/POST /staff-users` (`role`: `admin`\|`staff`; `password`, or `send_link: true` to email a link), `GET/PATCH /staff-users/{id}`, `POST /staff-users/{id}/activate`, `/deactivate` (revokes tokens and trusted devices; browser sessions end on their next request; not yourself, not the last active administrator), `/password-reset-link`, `/two-factor/reset` (`revoke_api_tokens`: also revoke the user's tokens; not yourself). Demoting the last active administrator is `422` |
| Portal logins | `GET/POST /clients/{id}/users`, `GET/PATCH /users/{id}`, `POST /users/{id}/password` (set), `/password-reset-link` (email), `/activate`, `/deactivate` (also revokes its API tokens), `/impersonate` |
| Services | `GET/POST /services` (`create_invoice: false` creates the service alone, with `amount`, `next_due_date`, `activate`), `GET/PATCH /services/{id}`, `GET /services/{id}/logs`, `POST /services/{id}/activate\|suspend\|unsuspend\|terminate\|cancel\|reveal`, `POST /services/{id}/actions/{action}`; trials and plan changes: see [Trials and plan changes](#trials-and-plan-changes) |
| Catalogue | `/products` and `/product-groups` (CRUD, `POST .../reorder` with `{"ids": [...]}`), `GET /modules` (modules of enabled plugins with their product/server field definitions) |
| Servers | `/servers` CRUD, `POST /servers/{id}/test` |
| Invoices | `/invoices` CRUD (drafts only for edit/delete), `GET /invoices/summary`, `GET /invoices/{id}/pdf`, `POST /invoices/{id}/issue\|resend\|void\|uncollectible`, `POST /invoices/{id}/payments` (manual payment). `pay_url` is the public link |
| Quotes | `/quotes` CRUD, `GET /quotes/{id}/pdf`, `POST /quotes/{id}/send\|convert\|accept\|decline\|duplicate`. `view_url` is the public link |
| Payments | `GET /payments`, `GET /payments/summary` (same filters; received/refunded/net per currency), `GET /payments/{id}`, `POST /payments/{id}/refund` (omit `amount` to refund the rest) |
| Support tickets | `/tickets`, `/ticket-departments`, `/ticket-fields`: see [Support tickets](#support-tickets) |
| Tax rates | `/tax-rates` CRUD |
| Saved payment methods | `GET /clients/{id}/payment-methods`, `DELETE /clients/{id}/payment-methods/{uuid}`, `GET /autopay/attempts` |
| Usage billing | `/products/{id}/meters` CRUD, `GET/POST /services/{id}/usage`, `DELETE /services/{id}/usage/{recordId}` |
| Settings | `GET /settings`, `GET/PUT /settings/{general\|billing\|email\|autopay\|security\|payments}`, `GET /settings/system`, `POST /settings/email/test`, `POST /settings/system/cron-token`, `POST /settings/system/run` |
| Plugins | `GET /plugins`, `GET /plugins/{slug}`, `POST /plugins/{slug}/enable\|disable`, `GET/PUT /plugins/{slug}/settings` |
| API tokens | `GET/POST /api-tokens`, `GET/DELETE /api-tokens/{id}` |
| Webhooks | `/webhooks` CRUD, `POST /webhooks/{id}/test`, `GET /webhooks/{id}/deliveries`, `GET /events` |
| Activity | `GET /activity` (`action`, `action=invoice.*`, `user_id`, `subject_type` + `subject_id` for one record's history, `from`, `to`) |
| About the caller | `GET /me` (staff user and the token's abilities), `GET /abilities` |

Deliberately **not** available through the API: **plugin upload and installation.** Uploading a plugin means putting executable PHP on the server, and that must never be possible with a bearer token that can leak from a CI log or a script. Install plugins from the admin screen (Admin → Plugins) or with `php artisan plugin install <zip>` on the server; the API can then list, enable, disable and configure installed plugins (`/admin/plugins`). Two-factor enrolment (QR code, recovery codes) is likewise a browser-only flow; the API only verifies codes at `POST /auth/token`.

### Settings

`GET /settings/{section}` returns the fields of a section with their current values (`general`, `billing`, `email`, `autopay` (the Automatic payments group of billing), `security` (the staff two-factor requirement)), or the gateways (`payments`), or read-only system info (`system`). Secret fields carry `"secret": true` and `has_value` instead of a value. Updates are partial and validated exactly like the admin form:

```bash
curl -X PUT $API/admin/settings/billing -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"settings": {"billing.due_days": 14, "billing.invoice_prefix": "ACME-"}}'

curl -X PUT $API/admin/settings/payments -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"gateways": {"stripe": {"enabled": true, "title": "Card", "secret_key": "sk_live_..."}}}'
```

Only the keys you send change; `null` resets a setting to its default; a blank secret keeps the stored one; unknown keys are a `422`. Plugin settings work the same way: `PUT /plugins/{slug}/settings {"settings": {...}}`. Servers take module fields in `config`.

### Servers and secrets

`POST /servers {"module": "cpanel", "name": "WHM 1", "hostname": "whm1.example.com", "secret": "...", "config": {...}}`. The responses contain `has_secret` and `config_secrets_set` but never the secrets. `GET /modules` tells you which `config` fields each module accepts. `POST /servers/{id}/test` returns `{"data": {"success": true, "message": "..."}}` (a failed connection is `success: false`, not an HTTP error).

### Tokens, impersonation

`POST /admin/api-tokens {"name": "CI", "abilities": ["invoices.read"], "expires_in_days": 90}` creates a token for yourself and returns it once. A token can only grant abilities it has itself, and a token with a limited ability list cannot create a full-access token. Administrators can list (`?user_id=` or `?all=1`) and revoke anyone's tokens.

`POST /admin/users/{id}/impersonate {"minutes": 15, "abilities": ["invoices.read"]}` returns a customer-scoped bearer token that expires after `minutes` (default 15, max 60). It is the API counterpart of "view as customer", can be limited to customer abilities, and is written to the activity log. Revoke it early with `DELETE /auth/token`.

## Keeping the spec and the code in step

`resources/openapi.json` is generated, never edited by hand. It is built from the real route list plus the descriptions in `app/Http/Controllers/Api/Support/OpenApi/` (`Operations.php` per route, `Schemas.php` for the shared schemas):

```bash
php artisan api:openapi           # regenerate resources/openapi.json
php artisan api:openapi --check   # CI: fail if it is out of date
```

To add an endpoint: add the route (name it, give it an `ability:` middleware; admin groups use `BindAfterAuth` after the ability check, see `routes/api/admin/*.php`), add its ability to `App\Support\Abilities` if it is new, add one entry to `Operations.php`, run the command. The command refuses to run when a route has no entry (or an entry has no route), and `tests/Feature/Api` fails when the committed spec is stale, when an ability is used but not listed, or when an admin route is reachable without a staff token or without its ability.
