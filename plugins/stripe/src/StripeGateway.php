<?php

namespace Plugins\Stripe;

use App\Contracts\SupportsSavedMethods;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Plugins\AbstractGateway;
use App\Services\PaymentMethods;
use App\Services\PaymentService;
use App\Support\Activity;
use App\Support\ChargeResult;
use App\Support\CheckoutContext;
use App\Support\CheckoutResult;
use App\Support\Money;
use App\Support\PaymentMethodData;
use App\Support\RefundResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class StripeGateway extends AbstractGateway implements SupportsSavedMethods
{
    private const API = 'https://api.stripe.com/v1';

    public function key(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return 'Stripe';
    }

    public function description(): string
    {
        return 'Pay by card or wallet through Stripe Checkout.';
    }

    public function fields(): array
    {
        return [
            ['key' => 'secret_key', 'label' => 'Secret key', 'type' => 'password', 'required' => true, 'help' => 'sk_live_… or sk_test_… (Developers → API keys).'],
            ['key' => 'webhook_secret', 'label' => 'Webhook signing secret', 'type' => 'password', 'required' => true, 'help' => 'whsec_… from the webhook endpoint you create in Stripe.'],
            ['key' => 'descriptor_suffix', 'label' => 'Statement descriptor suffix', 'type' => 'text', 'help' => 'Optional, up to 22 characters, shown on card statements.'],
        ];
    }

    public function checkout(Invoice $invoice, CheckoutContext $context): CheckoutResult
    {
        $success = $context->successUrl.(str_contains($context->successUrl, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}';
        $body = [
            'mode' => 'payment',
            'client_reference_id' => $invoice->uuid,
            'success_url' => $success,
            'cancel_url' => $context->cancelUrl,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($invoice->currency),
                    'unit_amount' => $this->toStripeAmount($invoice->balance(), $invoice->currency),
                    'product_data' => ['name' => 'Invoice '.$invoice->displayNumber()],
                ],
            ]],
            'metadata' => ['invoice_uuid' => $invoice->uuid, 'invoice_id' => (string) $invoice->id],
            'payment_intent_data' => ['metadata' => ['invoice_uuid' => $invoice->uuid, 'invoice_id' => (string) $invoice->id]],
        ];
        if ($context->customerEmail) {
            $body['customer_email'] = $context->customerEmail;
        }
        if ($suffix = $this->setting('descriptor_suffix')) {
            $body['payment_intent_data']['statement_descriptor_suffix'] = mb_substr((string) $suffix, 0, 22);
        }

        $response = $this->http()->post(self::API.'/checkout/sessions', $body);
        if (! $response->successful() || ! $response->json('url')) {
            return CheckoutResult::error($response->json('error.message') ?: 'Stripe could not start the payment.');
        }

        return CheckoutResult::redirect($response->json('url'));
    }

    public function complete(Request $request, Invoice $invoice): ?Payment
    {
        $id = $request->query('session_id');
        if (! $id || ! is_string($id)) {
            return null;
        }
        $response = $this->http()->get(self::API.'/checkout/sessions/'.urlencode($id), ['expand' => ['payment_intent']]);
        if (! $response->successful()) {
            return null;
        }
        $session = $response->json();
        if (($session['client_reference_id'] ?? null) !== $invoice->uuid) {
            return null;
        }

        return $this->recordSession($invoice, $session);
    }

    public function webhook(Request $request): Response
    {
        if (! $this->verifySignature($request->getContent(), (string) $request->header('Stripe-Signature'))) {
            return response('Invalid signature', 400);
        }
        $event = json_decode($request->getContent(), true);
        $object = $event['data']['object'] ?? [];

        try {
            match ($event['type'] ?? '') {
                'checkout.session.completed', 'checkout.session.async_payment_succeeded' => ($object['mode'] ?? '') === 'setup' ? $this->onSetupSession($object) : $this->onSessionPaid($object),
                'payment_intent.succeeded' => $this->onAutopayIntent($object),
                'payment_method.detached' => app(PaymentMethods::class)->markRemoved('stripe', (string) ($object['id'] ?? '')),
                'payment_method.updated', 'payment_method.automatically_updated' => $this->onMethodUpdated($object),
                'setup_intent.setup_failed' => $this->onSetupFailed($object),
                'charge.refunded' => $this->onChargeRefunded($object),
                'refund.created', 'refund.updated' => $this->onRefund($object),
                default => null,
            };
        } catch (\DomainException $e) {
            // e.g. invoice was voided: acknowledge so Stripe stops retrying
        }

        return response('ok', 200);
    }

    public function refund(Payment $payment, int $amount, ?string $reason = null): RefundResult
    {
        $intent = $payment->reference;
        $response = $this->http()->post(self::API.'/refunds', array_filter([
            'payment_intent' => $intent,
            'amount' => $this->toStripeAmount($amount, $payment->currency),
            'metadata' => $reason ? ['reason' => mb_substr($reason, 0, 500)] : null,
        ]));
        if (! $response->successful()) {
            return RefundResult::fail($response->json('error.message') ?: 'Stripe refused the refund.');
        }

        return RefundResult::ok($response->json('id'));
    }

    // ---- Saved payment methods (SupportsSavedMethods) -------------------------------------------------------

    public function beginSetup(Client $client, CheckoutContext $context): CheckoutResult
    {
        $customer = $this->customerFor($client);
        if (! $customer) {
            return CheckoutResult::error('Stripe could not create your customer record.');
        }
        $success = $context->successUrl.(str_contains($context->successUrl, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}';
        $autopay = str_contains($context->successUrl, 'autopay=1') ? '1' : '0';
        $response = $this->http()->post(self::API.'/checkout/sessions', [
            'mode' => 'setup',
            'customer' => $customer,
            'currency' => strtolower($client->currency ?: 'usd'),
            'payment_method_types' => ['card'],
            'client_reference_id' => $client->uuid,
            'success_url' => $success,
            'cancel_url' => $context->cancelUrl,
            'metadata' => ['client_uuid' => $client->uuid, 'autopay' => $autopay],
            'setup_intent_data' => ['metadata' => ['client_uuid' => $client->uuid]],
        ]);
        if (! $response->successful() || ! $response->json('url')) {
            return CheckoutResult::error($response->json('error.message') ?: 'Stripe could not start the setup.');
        }

        return CheckoutResult::redirect($response->json('url'));
    }

    public function completeSetup(Request $request, Client $client): ?PaymentMethodData
    {
        $id = $request->query('session_id');
        if (! $id || ! is_string($id)) {
            return null;
        }
        $response = $this->http()->get(self::API.'/checkout/sessions/'.urlencode($id), ['expand' => ['setup_intent.payment_method']]);
        if (! $response->successful()) {
            return null;
        }

        return $this->methodFromSession($response->json(), $client);
    }

    public function charge(PaymentMethod $method, Invoice $invoice, ?string $idempotencyKey = null): ChargeResult
    {
        $amount = $invoice->balance();
        $request = $this->http();
        if ($idempotencyKey) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }
        $response = $request->post(self::API.'/payment_intents', [
            'amount' => $this->toStripeAmount($amount, $invoice->currency),
            'currency' => strtolower($invoice->currency),
            'customer' => $method->customer_reference,
            'payment_method' => $method->reference,
            'off_session' => 'true',
            'confirm' => 'true',
            'description' => 'Invoice '.$invoice->displayNumber(),
            'metadata' => ['invoice_uuid' => $invoice->uuid, 'invoice_id' => (string) $invoice->id, 'autopay' => '1'],
        ]);
        $body = $response->json() ?? [];

        if ($response->successful()) {
            $intent = $body;
            $status = $intent['status'] ?? '';
            if ($status === 'succeeded') {
                return ChargeResult::success($intent['id'], $this->fromStripeAmount((int) ($intent['amount_received'] ?? $amount), $invoice->currency), 0, ['payment_intent' => $intent['id'], 'charge' => $intent['latest_charge'] ?? null]);
            }
            if ($status === 'requires_action' || $status === 'requires_confirmation') {
                return ChargeResult::requiresAction('Stripe needs the customer to authenticate this payment.', $intent['id'] ?? null);
            }
            if ($status === 'processing') {
                return ChargeResult::pending($intent['id'], 'Stripe is still processing the payment.');
            }

            return ChargeResult::failed('Stripe returned status '.$status.'.');
        }

        $error = $body['error'] ?? [];
        if (($error['code'] ?? '') === 'authentication_required' || ($error['decline_code'] ?? '') === 'authentication_required') {
            return ChargeResult::requiresAction('The card issuer requires the customer to authenticate this payment.', $error['payment_intent']['id'] ?? null);
        }

        return ChargeResult::failed($error['message'] ?? 'Stripe declined the payment.');
    }

    public function delete(PaymentMethod $method): bool
    {
        $response = $this->http()->post(self::API.'/payment_methods/'.urlencode($method->reference).'/detach');

        // A method that is already detached/missing counts as removed.
        return $response->successful() || ($response->json('error.code') === 'resource_missing') || str_contains((string) $response->json('error.message'), 'not attached');
    }

    /** The Stripe Customer for a client, created on first use. */
    private function customerFor(Client $client): ?string
    {
        $methods = app(PaymentMethods::class);
        if ($existing = $methods->customerReference($client, 'stripe')) {
            return $existing;
        }
        $response = $this->http()->post(self::API.'/customers', array_filter([
            'email' => $client->email,
            'name' => $client->displayName(),
            'metadata' => ['client_uuid' => $client->uuid],
        ]));
        if (! $response->successful() || ! $response->json('id')) {
            return null;
        }
        $methods->rememberCustomer($client, 'stripe', $response->json('id'));

        return $response->json('id');
    }

    /** @param  array<string, mixed>  $session  a Checkout Session in setup mode with setup_intent.payment_method expanded */
    private function methodFromSession(array $session, Client $client): ?PaymentMethodData
    {
        if (($session['mode'] ?? '') !== 'setup' || ($session['status'] ?? '') !== 'complete'
            || ($session['metadata']['client_uuid'] ?? $session['client_reference_id'] ?? null) !== $client->uuid) {
            return null;
        }
        $pm = $session['setup_intent']['payment_method'] ?? null;
        if (! is_array($pm) || empty($pm['id'])) {
            return null;
        }

        return $this->dataFromPaymentMethod($pm, is_string($session['customer'] ?? null) ? $session['customer'] : ($pm['customer'] ?? null));
    }

    /** @param  array<string, mixed>  $pm */
    private function dataFromPaymentMethod(array $pm, ?string $customer = null): PaymentMethodData
    {
        $type = $pm['type'] ?? 'card';
        $card = $pm['card'] ?? [];

        return new PaymentMethodData(
            reference: $pm['id'],
            customerReference: $customer ?? (is_string($pm['customer'] ?? null) ? $pm['customer'] : null),
            brand: $type === 'card' ? ($card['brand'] ?? 'card') : $type,
            last4: $card['last4'] ?? null,
            expMonth: isset($card['exp_month']) ? (int) $card['exp_month'] : null,
            expYear: isset($card['exp_year']) ? (int) $card['exp_year'] : null,
            label: $pm['billing_details']['email'] ?? null,
        );
    }

    /** @param  array<string, mixed>  $session */
    private function onSetupSession(array $session): void
    {
        $uuid = $session['metadata']['client_uuid'] ?? null;
        $client = $uuid ? Client::where('uuid', $uuid)->first() : null;
        $id = $session['id'] ?? null;
        if (! $client || ! $id) {
            return;
        }
        $response = $this->http()->get(self::API.'/checkout/sessions/'.urlencode($id), ['expand' => ['setup_intent.payment_method']]);
        $data = $response->successful() ? $this->methodFromSession($response->json(), $client) : null;
        if ($data) {
            app(PaymentMethods::class)->store($client, 'stripe', $data, ($session['metadata']['autopay'] ?? '0') === '1');
        }
    }

    /** @param  array<string, mixed>  $intent  an off-session PaymentIntent we created */
    private function onAutopayIntent(array $intent): void
    {
        if (($intent['metadata']['autopay'] ?? null) !== '1') {
            return;
        }
        $invoice = ($u = $intent['metadata']['invoice_uuid'] ?? null) ? Invoice::where('uuid', $u)->first() : null;
        if ($invoice) {
            app(PaymentService::class)->record($invoice, 'stripe', $this->fromStripeAmount((int) ($intent['amount_received'] ?? $intent['amount']), $invoice->currency), $intent['id'], ['meta' => array_filter(['payment_intent' => $intent['id'], 'charge' => $intent['latest_charge'] ?? null])]);
        }
    }

    /** @param  array<string, mixed>  $pm */
    private function onMethodUpdated(array $pm): void
    {
        if (! empty($pm['id'])) {
            app(PaymentMethods::class)->updateDetails('stripe', $pm['id'], $this->dataFromPaymentMethod($pm));
        }
    }

    /** @param  array<string, mixed>  $intent */
    private function onSetupFailed(array $intent): void
    {
        Activity::log('payment_method.setup_failed', null, 'Stripe could not save a payment method: '.($intent['last_setup_error']['message'] ?? 'unknown error'), ['setup_intent' => $intent['id'] ?? null, 'client_uuid' => $intent['metadata']['client_uuid'] ?? null]);
    }

    /** Stripe wants three-decimal currencies rounded to a multiple of ten. */
    private function toStripeAmount(int $minor, string $currency): int
    {
        return Money::digits($currency) === 3 ? intdiv($minor + 5, 10) * 10 : $minor;
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) $this->setting('secret_key'))->asForm()->acceptJson()->timeout(20);
    }

    /** @param  array<string, mixed>  $session */
    private function recordSession(Invoice $invoice, array $session): ?Payment
    {
        if (($session['payment_status'] ?? '') !== 'paid') {
            return null;
        }
        $intent = $session['payment_intent'] ?? null;
        $intentId = is_array($intent) ? ($intent['id'] ?? null) : $intent;
        $charge = is_array($intent) ? ($intent['latest_charge'] ?? null) : null;

        return app(PaymentService::class)->record(
            $invoice,
            'stripe',
            $this->fromStripeAmount((int) $session['amount_total'], $invoice->currency),
            $intentId ?: $session['id'],
            ['meta' => array_filter(['payment_intent' => $intentId, 'charge' => is_string($charge) ? $charge : null, 'session' => $session['id'] ?? null])],
        );
    }

    private function fromStripeAmount(int $amount, string $currency): int
    {
        return $amount;
    }

    /** @param  array<string, mixed>  $session */
    private function onSessionPaid(array $session): void
    {
        $uuid = $session['metadata']['invoice_uuid'] ?? $session['client_reference_id'] ?? null;
        $invoice = $uuid ? Invoice::where('uuid', $uuid)->first() : null;
        if ($invoice) {
            $this->recordSession($invoice, $session);
        }
    }

    /** @param  array<string, mixed>  $charge */
    private function onChargeRefunded(array $charge): void
    {
        $original = $this->originalFor($charge['payment_intent'] ?? null);
        if (! $original) {
            return;
        }
        $refunds = $charge['refunds']['data'] ?? [];
        if ($refunds) {
            foreach ($refunds as $r) {
                if (($r['status'] ?? 'succeeded') === 'succeeded') {
                    app(PaymentService::class)->recordRefund($original, (int) $r['amount'], $r['id']);
                }
            }

            return;
        }
        // No refund list on the event: record whatever total is not accounted for yet.
        $delta = (int) ($charge['amount_refunded'] ?? 0) - $original->refundedAmount();
        if ($delta > 0) {
            app(PaymentService::class)->recordRefund($original, $delta, ($charge['id'] ?? 'charge').':'.$charge['amount_refunded']);
        }
    }

    /** @param  array<string, mixed>  $refund */
    private function onRefund(array $refund): void
    {
        if (($refund['status'] ?? '') !== 'succeeded' || empty($refund['id'])) {
            return;
        }
        if ($original = $this->originalFor($refund['payment_intent'] ?? null)) {
            app(PaymentService::class)->recordRefund($original, (int) $refund['amount'], $refund['id']);
        }
    }

    private function originalFor(mixed $intent): ?Payment
    {
        return $intent ? Payment::where(['gateway' => 'stripe', 'reference' => $intent, 'type' => 'payment'])->first() : null;
    }

    private function verifySignature(string $payload, string $header, int $tolerance = 300): bool
    {
        $secret = (string) $this->setting('webhook_secret');
        if ($secret === '' || $header === '') {
            return false;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $timestamp = (int) $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }
        if (! $timestamp || ! $signatures || abs(time() - $timestamp) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }
}
