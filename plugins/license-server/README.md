# License Server plugin

Issues licence keys when an order is paid and lets your software verify, activate and deactivate them.

## Setup
1. Enable the plugin (Plugins page). Migrations run and an Ed25519 signing keypair is generated.
2. Create a product, module **Software licence**: product code (what your software sends as `product`), key prefix, max activations, binding (`domain`, `ip`, `instance`, `none`), optional grace days.
3. When an order is paid, a key like `ACME-7KQ2-XM4D-R9TP-H3NA` is issued, shown on the service page (client and admin) and under **Licenses** in the admin menu.

A licence follows the service: suspended on overdue invoices (with optional grace days), reactivated on payment, revoked on termination. Admins can also set a hard expiry date.

## API (public, JSON, rate limited)
Base: `/api/v1/x/license-server`

| Method | Path | Body |
|---|---|---|
| POST | `/verify` | `key`, `product`, `identifier`?, `version`? |
| POST | `/activate` | same |
| POST | `/deactivate` | same |
| GET | `/public-key` | |

`identifier` is a domain (normalised: scheme, `www.`, port and path are stripped), an IP (defaults to the caller's IP for `ip` binding) or any instance id. Unknown key, malformed key and wrong product all return the same `403 invalid`. Errors: `identifier_required` (422), `not_activated`, `suspended`, `revoked`, `expired` (403), `activation_limit` (409). Activating the same identifier twice is a no-op.

Success: `{ "valid": true, "status": "active|grace", "expires_at": null|ISO8601, "issued_at": 1700000000, "activations": {"used":1,"max":2}, "signature": "<base64>" }`

## Verify offline
The signature is Ed25519 over this exact string (newline separated): `license.v1`, `valid=1`, `key=…`, `product=…`, `status=…`, `expires=<expires_at or empty>`, `identifier=<normalised or empty>`, `issued=<issued_at>`.

```php
function licenseIsValid(array $r, string $key, string $product, string $identifier, string $publicKeyB64): bool
{
    $payload = implode("\n", [
        'license.v1', 'valid=1', "key=$key", "product=$product", 'status='.$r['status'],
        'expires='.($r['expires_at'] ?? ''), "identifier=$identifier", 'issued='.$r['issued_at'],
    ]);
    $fresh = time() - $r['issued_at'] < 7 * 86400; // your own offline grace window
    return $fresh && sodium_crypto_sign_verify_detached(base64_decode($r['signature']), $payload, base64_decode($publicKeyB64));
}

$res = json_decode(file_get_contents('https://billing.example.com/api/v1/x/license-server/verify', false, stream_context_create(['http' => [
    'method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'ignore_errors' => true,
    'content' => json_encode(['key' => $key, 'product' => 'acme-pro', 'identifier' => 'example.com']),
]])), true);
```
Embed the public key (shown on the admin Licenses page) in your software; cache the last good response to allow offline use. The identifier in the payload is the normalised one (lowercase host).

## Extending
- Event `Plugins\LicenseServer\Events\LicenseRevoked` (listen with `Event::listen`).
- Filter `Hooks::filter('license-server.response', fn (array $body, License $l, Request $r) => $body)` adds (unsigned) fields to responses.
- Plugin settings: default grace days, whether verify updates "last seen".
