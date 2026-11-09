<?php

namespace Plugins\PayPal;

use App\Contracts\SupportsSavedMethods;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Plugins\AbstractGateway;
use App\Services\PaymentMethods;
use App\Services\PaymentService;
use App\Support\ChargeResult;
use App\Support\CheckoutContext;
use App\Support\CheckoutResult;
use App\Support\Money;
use App\Support\PaymentMethodData;
use App\Support\RefundResult;
use App\Support\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class PayPalGateway extends AbstractGateway implements SupportsSavedMethods
{
    private const CURRENCIES = ['AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD'];

    public function key(): string
    {
        return 'paypal';
    }

    public function label(): string
    {
        return 'PayPal';
    }

    public function description(): string
    {
        return 'Pay with a PayPal account or card through PayPal.';
    }

    public function fields(): array
    {
        return [
            ['key' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'Sandbox (testing)', 'live' => 'Live'], 'default' => 'sandbox'],
            ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true],
            ['key' => 'client_secret', 'label' => 'Client secret', 'type' => 'password', 'required' => true],
            ['key' => 'webhook_id', 'label' => 'Webhook ID', 'type' => 'text', 'required' => true, 'help' => 'The ID of the webhook you create in the PayPal developer dashboard.'],
        ];
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), self::CURRENCIES, true);
    }

    public function checkout(Invoice $invoice, CheckoutContext $context): CheckoutResult
    {
        $response = $this->api()->post($this->base().'/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'custom_id' => $invoice->uuid,
                'invoice_id' => $invoice->displayNumber(),
                'description' => 'Invoice '.$invoice->displayNumber(),
                'amount' => ['currency_code' => strtoupper($invoice->currency), 'value' => Money::toDecimal($invoice->balance(), $invoice->currency)],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'return_url' => $context->successUrl,
                'cancel_url' => $context->cancelUrl,
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
            ]]],
        ]);
        if (! $response->successful()) {
            return CheckoutResult::error($response->json('message') ?: 'PayPal could not start the payment.');
        }
        foreach ($response->json('links', []) as $link) {
            if (in_array($link['rel'] ?? '', ['payer-action', 'approve'], true)) {
                return CheckoutResult::redirect($link['href']);
            }
        }

        return CheckoutResult::error('PayPal did not return an approval link.');
    }

    public function complete(Request $request, Invoice $invoice): ?Payment
    {
        $orderId = $request->query('token');
        if (! $orderId || ! is_string($orderId)) {
            return null;
        }
        $url = $this->base().'/v2/checkout/orders/'.urlencode($orderId);
        $response = $this->api()->withBody('{}', 'application/json')->post($url.'/capture');
        if (! $response->successful()) {
            $already = collect($response->json('details', []))->contains(fn ($d) => ($d['issue'] ?? '') === 'ORDER_ALREADY_CAPTURED');
            if (! $already) {
                return null;
            }
            $response = $this->api()->get($url);
            if (! $response->successful()) {
                return null;
            }
        }
        $order = $response->json();
        $unit = $order['purchase_units'][0] ?? [];
        $capture = $unit['payments']['captures'][0] ?? null;
        if (($order['status'] ?? '') !== 'COMPLETED' || ! $capture || ($unit['custom_id'] ?? $capture['custom_id'] ?? null) !== $invoice->uuid) {
            return null;
        }

        return $this->recordCapture($invoice, $capture, $orderId);
    }

    public function webhook(Request $request): Response
    {
        $event = json_decode($request->getContent(), true);
        if (! is_array($event) || ! $this->verify($request, $event)) {
            return response('Invalid signature', 400);
        }
        $resource = $event['resource'] ?? [];

        try {
            match ($event['event_type'] ?? '') {
                'PAYMENT.CAPTURE.COMPLETED' => $this->onCaptureCompleted($resource),
                'PAYMENT.CAPTURE.REFUNDED' => $this->onRefunded($resource),
                'VAULT.PAYMENT-TOKEN.DELETED' => app(PaymentMethods::class)->markRemoved('paypal', (string) ($resource['id'] ?? '')),
                default => null,
            };
        } catch (\DomainException $e) {
            // e.g. invoice voided meanwhile: acknowledge so PayPal stops retrying
        }

        return response('ok', 200);
    }

    public function refund(Payment $payment, int $amount, ?string $reason = null): RefundResult
    {
        $captureId = $payment->meta['capture_id'] ?? $payment->reference;
        $response = $this->api()->post($this->base().'/v2/payments/captures/'.urlencode((string) $captureId).'/refund', array_filter([
            'amount' => ['value' => Money::toDecimal($amount, $payment->currency), 'currency_code' => strtoupper($payment->currency)],
            'note_to_payer' => $reason ? mb_substr($reason, 0, 255) : null,
        ]));
        if (! $response->successful()) {
            return RefundResult::fail($response->json('message') ?: 'PayPal refused the refund.');
        }

        return RefundResult::ok($response->json('id'));
    }

    // ---- Saved payment methods (SupportsSavedMethods), PayPal Vault v3 --------------------------------------
    // Wallet only: PayPal saves cards through its JS SDK / Hosted Fields, which has no redirect flow.

    public function beginSetup(Client $client, CheckoutContext $context): CheckoutResult
    {
        $paypal = ['usage_type' => 'MERCHANT', 'experience_context' => [
            'brand_name' => (string) (Settings::get('company.name') ?: config('billing.name')),
            'return_url' => $context->successUrl,
            'cancel_url' => $context->cancelUrl,
        ]];
        $body = ['payment_source' => ['paypal' => $paypal]];
        if ($customer = app(PaymentMethods::class)->customerReference($client, 'paypal')) {
            $body['customer'] = ['id' => $customer];
        }
        $response = $this->api()->withHeaders(['PayPal-Request-Id' => 'setup-'.$client->uuid.'-'.now()->timestamp])->post($this->base().'/v3/vault/setup-tokens', $body);
        if (! $response->successful() || ! $response->json('id')) {
            return CheckoutResult::error($response->json('message') ?: 'PayPal could not start the setup.');
        }
        foreach ($response->json('links', []) as $link) {
            if (in_array($link['rel'] ?? '', ['approve', 'payer-action'], true)) {
                Cache::put('paypal.setup.'.$client->id, $response->json('id'), now()->addHour());

                return CheckoutResult::redirect($link['href']);
            }
        }

        return CheckoutResult::error('PayPal did not return an approval link.');
    }

    public function completeSetup(Request $request, Client $client): ?PaymentMethodData
    {
        // The setup token id never travels through the browser: it is remembered server-side per client.
        $setupId = Cache::pull('paypal.setup.'.$client->id);
        if (! $setupId) {
            return null;
        }
        $response = $this->api()->withHeaders(['PayPal-Request-Id' => 'token-'.$setupId])->post($this->base().'/v3/vault/payment-tokens', [
            'payment_source' => ['token' => ['id' => $setupId, 'type' => 'SETUP_TOKEN']],
        ]);
        if (! $response->successful() || ! $response->json('id')) {
            return null;
        }
        $source = $response->json('payment_source', []);
        $card = $source['card'] ?? null;
        [$exp_year, $exp_month] = $card && ! empty($card['expiry']) ? array_map('intval', explode('-', $card['expiry'].'-0')) : [null, null];

        return new PaymentMethodData(
            reference: $response->json('id'),
            customerReference: $response->json('customer.id'),
            brand: $card ? ($card['brand'] ?? 'card') : 'paypal',
            last4: $card['last_digits'] ?? null,
            expMonth: $exp_month ?: null,
            expYear: $exp_year ?: null,
            label: $source['paypal']['email_address'] ?? null,
            data: ['type' => $card ? 'card' : 'paypal'],
        );
    }

    public function charge(PaymentMethod $method, Invoice $invoice, ?string $idempotencyKey = null): ChargeResult
    {
        $isCard = ($method->data['type'] ?? 'paypal') === 'card';
        $source = $isCard
            ? ['card' => ['vault_id' => $method->reference, 'stored_credential' => ['payment_initiator' => 'MERCHANT', 'payment_type' => 'UNSCHEDULED', 'usage' => 'SUBSEQUENT']]]
            : ['paypal' => ['vault_id' => $method->reference]];
        $request = $this->api();
        if ($idempotencyKey) {
            $request = $request->withHeaders(['PayPal-Request-Id' => $idempotencyKey]);
        }
        $response = $request->post($this->base().'/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'custom_id' => $invoice->uuid,
                'invoice_id' => $invoice->displayNumber(),
                'description' => 'Invoice '.$invoice->displayNumber(),
                'amount' => ['currency_code' => strtoupper($invoice->currency), 'value' => Money::toDecimal($invoice->balance(), $invoice->currency)],
            ]],
            'payment_source' => $source,
        ]);

        if (! $response->successful()) {
            $issues = collect($response->json('details', []))->pluck('issue');
            if ($issues->contains('PAYER_ACTION_REQUIRED') || $issues->contains('PAYER_ACTION_REQUIRED_ERROR')) {
                return ChargeResult::requiresAction('PayPal needs the customer to approve this payment.');
            }
            $detail = $response->json('details.0.description') ?: $response->json('message');

            return ChargeResult::failed($detail ?: 'PayPal declined the payment.');
        }
        $order = $response->json();
        if (($order['status'] ?? '') === 'PAYER_ACTION_REQUIRED') {
            return ChargeResult::requiresAction('PayPal needs the customer to approve this payment.', $order['id'] ?? null);
        }
        if (($order['status'] ?? '') === 'APPROVED') { // not captured automatically: capture it now
            $captured = $this->api()->withBody('{}', 'application/json')->withHeaders($idempotencyKey ? ['PayPal-Request-Id' => $idempotencyKey.'-capture'] : [])
                ->post($this->base().'/v2/checkout/orders/'.urlencode($order['id']).'/capture');
            if (! $captured->successful()) {
                return ChargeResult::failed($captured->json('details.0.description') ?: $captured->json('message') ?: 'PayPal could not capture the payment.');
            }
            $order = $captured->json();
        }
        $capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;
        if (($order['status'] ?? '') === 'COMPLETED' && $capture && ($capture['status'] ?? '') === 'COMPLETED') {
            $currency = $capture['amount']['currency_code'];
            $fee = $capture['seller_receivable_breakdown']['paypal_fee']['value'] ?? null;

            return ChargeResult::success($capture['id'], Money::toMinor($capture['amount']['value'], $currency), $fee !== null ? Money::toMinor($fee, $currency) : 0, ['capture_id' => $capture['id'], 'order_id' => $order['id'] ?? null]);
        }
        if ($capture && ($capture['status'] ?? '') === 'PENDING') {
            return ChargeResult::pending($capture['id'], 'PayPal is holding this payment for review.');
        }

        return ChargeResult::failed('PayPal did not complete the payment (status '.($order['status'] ?? 'unknown').').');
    }

    public function delete(PaymentMethod $method): bool
    {
        $response = $this->api()->delete($this->base().'/v3/vault/payment-tokens/'.urlencode($method->reference));

        return $response->successful() || $response->status() === 404;
    }

    private function base(): string
    {
        return $this->setting('mode', 'sandbox') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private function api(): PendingRequest
    {
        return Http::withToken($this->token())->acceptJson()->asJson()->timeout(20);
    }

    private function token(): string
    {
        $cacheKey = 'paypal.token.'.$this->setting('mode', 'sandbox').'.'.sha1((string) $this->setting('client_id'));
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }
        $response = Http::withBasicAuth((string) $this->setting('client_id'), (string) $this->setting('client_secret'))
            ->asForm()->acceptJson()->timeout(20)->post($this->base().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        $this->ensure($response, 'PayPal authentication failed');
        Cache::put($cacheKey, $response->json('access_token'), max(60, (int) $response->json('expires_in', 300) - 60));

        return $response->json('access_token');
    }

    private function ensure(HttpResponse $response, string $message): void
    {
        if (! $response->successful() || ! $response->json('access_token')) {
            throw new \RuntimeException($message.' ('.$response->status().').');
        }
    }

    /** @param  array<string, mixed>  $capture */
    private function recordCapture(Invoice $invoice, array $capture, ?string $orderId = null): ?Payment
    {
        if (($capture['status'] ?? '') !== 'COMPLETED') {
            return null;
        }
        $fee = $capture['seller_receivable_breakdown']['paypal_fee']['value'] ?? null;

        return app(PaymentService::class)->record(
            $invoice,
            'paypal',
            Money::toMinor($capture['amount']['value'], $capture['amount']['currency_code']),
            $capture['id'],
            [
                'fee' => $fee !== null ? Money::toMinor($fee, $capture['amount']['currency_code']) : 0,
                'meta' => array_filter(['capture_id' => $capture['id'], 'order_id' => $orderId]),
            ],
        );
    }

    /** @param  array<string, mixed>  $capture */
    private function onCaptureCompleted(array $capture): void
    {
        $uuid = $capture['custom_id'] ?? null;
        if ($uuid && $invoice = Invoice::where('uuid', $uuid)->first()) {
            $this->recordCapture($invoice, $capture);
        }
    }

    /** @param  array<string, mixed>  $refund */
    private function onRefunded(array $refund): void
    {
        $captureId = null;
        foreach ($refund['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === 'up' && preg_match('#/captures/([^/?]+)#', $link['href'] ?? '', $m)) {
                $captureId = $m[1];
            }
        }
        $original = $captureId ? Payment::where(['gateway' => 'paypal', 'reference' => $captureId, 'type' => 'payment'])->first() : null;
        if ($original && ! empty($refund['id'])) {
            app(PaymentService::class)->recordRefund($original, Money::toMinor($refund['amount']['value'], $refund['amount']['currency_code']), $refund['id']);
        }
    }

    /** @param  array<string, mixed>  $event */
    private function verify(Request $request, array $event): bool
    {
        $headers = [
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
            'cert_url' => $request->header('PAYPAL-CERT-URL'),
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
        ];
        if (in_array(null, $headers, true) || ! $this->setting('webhook_id')) {
            return false;
        }
        try {
            $response = $this->api()->post($this->base().'/v1/notifications/verify-webhook-signature', $headers + [
                'webhook_id' => $this->setting('webhook_id'),
                'webhook_event' => $event,
            ]);
        } catch (\Throwable) {
            return false;
        }

        return $response->successful() && $response->json('verification_status') === 'SUCCESS';
    }
}
