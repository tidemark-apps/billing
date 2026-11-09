<?php

namespace Tests\Plugins\Stripe;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Plugins\GatewayRegistry;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\CheckoutContext;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StripeGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('stripe');
        Settings::set('gateway.stripe.secret_key', 'sk_test_123', true);
        Settings::set('gateway.stripe.webhook_secret', self::SECRET, true);
        Settings::set('gateway.stripe.enabled', true);
    }

    private function invoice(int $price = 2500, string $currency = 'USD'): Invoice
    {
        $client = Client::factory()->create(['currency' => $currency]);

        return app(InvoiceService::class)->create($client, [['description' => 'Hosting', 'unit_price' => $price, 'taxable' => false]], ['issue' => true, 'currency' => $currency]);
    }

    private function ctx(): CheckoutContext
    {
        return new CheckoutContext('https://app.test/pay/x/return/stripe', 'https://app.test/pay/x/cancel', 'https://app.test/webhooks/gateways/stripe', 'a@b.test');
    }

    private function gateway()
    {
        return app(GatewayRegistry::class)->get('stripe');
    }

    private function signed(array $event, ?int $time = null, ?string $secret = null): array
    {
        $body = json_encode($event);
        $t = $time ?? time();

        return [$body, ['HTTP_STRIPE_SIGNATURE' => "t=$t,v1=".hash_hmac('sha256', $t.'.'.$body, $secret ?? self::SECRET)]];
    }

    private function hook(string $body, array $server)
    {
        return $this->call('POST', '/webhooks/gateways/stripe', [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], $body);
    }

    private function paidSession(Invoice $invoice, string $intent = 'pi_1'): array
    {
        return ['id' => 'cs_1', 'payment_status' => 'paid', 'amount_total' => $invoice->total, 'payment_intent' => $intent, 'client_reference_id' => $invoice->uuid, 'metadata' => ['invoice_uuid' => $invoice->uuid]];
    }

    public function test_checkout_builds_session_request(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['url' => 'https://checkout.stripe.com/c/1'])]);
        $invoice = $this->invoice();

        $result = $this->gateway()->checkout($invoice, $this->ctx());

        $this->assertSame('https://checkout.stripe.com/c/1', $result->redirectUrl);
        Http::assertSent(function ($r) use ($invoice) {
            parse_str($r->body(), $b);

            return $r->url() === 'https://api.stripe.com/v1/checkout/sessions'
                && $r->hasHeader('Authorization', 'Bearer sk_test_123')
                && $b['mode'] === 'payment'
                && $b['line_items'][0]['price_data']['unit_amount'] === '2500'
                && $b['line_items'][0]['price_data']['currency'] === 'usd'
                && $b['client_reference_id'] === $invoice->uuid
                && $b['metadata']['invoice_uuid'] === $invoice->uuid
                && $b['payment_intent_data']['metadata']['invoice_uuid'] === $invoice->uuid
                && $b['success_url'] === 'https://app.test/pay/x/return/stripe?session_id={CHECKOUT_SESSION_ID}'
                && $b['customer_email'] === 'a@b.test';
        });
    }

    public function test_zero_decimal_currency_amount_is_not_scaled(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['url' => 'https://x'])]);
        $this->gateway()->checkout($this->invoice(5000, 'JPY'), $this->ctx());

        Http::assertSent(function ($r) {
            parse_str($r->body(), $b);

            return $b['line_items'][0]['price_data']['unit_amount'] === '5000' && $b['line_items'][0]['price_data']['currency'] === 'jpy';
        });
    }

    public function test_checkout_error_is_returned(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'Invalid API Key']], 401)]);
        $result = $this->gateway()->checkout($this->invoice(), $this->ctx());

        $this->assertTrue($result->failed());
        $this->assertSame('Invalid API Key', $result->error);
    }

    public function test_complete_records_once(): void
    {
        $invoice = $this->invoice();
        Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response(['payment_intent' => ['id' => 'pi_1', 'latest_charge' => 'ch_1']] + $this->paidSession($invoice))]);
        $req = Request::create('/x', 'GET', ['session_id' => 'cs_1']);

        $first = $this->gateway()->complete($req, $invoice);
        $second = $this->gateway()->complete($req, $invoice);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Payment::count());
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame('pi_1', $first->reference);
        $this->assertSame('ch_1', $first->meta['charge']);
    }

    public function test_complete_rejects_session_for_other_invoice(): void
    {
        $invoice = $this->invoice();
        $other = $this->invoice();
        Http::fake(['api.stripe.com/*' => Http::response($this->paidSession($other))]);

        $this->assertNull($this->gateway()->complete(Request::create('/x', 'GET', ['session_id' => 'cs_1']), $invoice));
        $this->assertSame(0, Payment::count());
    }

    public function test_webhook_records_payment_over_http_and_is_idempotent_with_complete(): void
    {
        $invoice = $this->invoice();
        [$body, $server] = $this->signed(['type' => 'checkout.session.completed', 'data' => ['object' => $this->paidSession($invoice)]]);

        $this->hook($body, $server)->assertOk();
        $this->hook($body, $server)->assertOk();

        Http::fake(['api.stripe.com/*' => Http::response($this->paidSession($invoice))]);
        $this->gateway()->complete(Request::create('/x', 'GET', ['session_id' => 'cs_1']), $invoice);

        $this->assertSame(1, Payment::count());
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
    }

    public function test_webhook_signature_checks(): void
    {
        $invoice = $this->invoice();
        $event = ['type' => 'checkout.session.completed', 'data' => ['object' => $this->paidSession($invoice)]];

        [$body, $server] = $this->signed($event, null, 'whsec_wrong');
        $this->hook($body, $server)->assertStatus(400);

        [$body, $server] = $this->signed($event);
        $this->hook($body.' ', $server)->assertStatus(400);

        [$body, $server] = $this->signed($event, time() - 3600);
        $this->hook($body, $server)->assertStatus(400);

        $this->hook($body, [])->assertStatus(400);
        $this->assertSame(0, Payment::count());
    }

    public function test_unpaid_session_and_unknown_invoice_are_acknowledged_without_recording(): void
    {
        $invoice = $this->invoice();
        $unpaid = $this->paidSession($invoice);
        $unpaid['payment_status'] = 'unpaid';
        [$b, $s] = $this->signed(['type' => 'checkout.session.completed', 'data' => ['object' => $unpaid]]);
        $this->hook($b, $s)->assertOk();

        $unknown = ['payment_status' => 'paid', 'id' => 'cs_x', 'amount_total' => 1, 'metadata' => ['invoice_uuid' => 'nope']];
        [$b, $s] = $this->signed(['type' => 'checkout.session.completed', 'data' => ['object' => $unknown]]);
        $this->hook($b, $s)->assertOk();

        $this->assertSame(0, Payment::count());
    }

    public function test_refund_calls_stripe_and_records_once_even_when_webhook_repeats(): void
    {
        $invoice = $this->invoice();
        [$b, $s] = $this->signed(['type' => 'checkout.session.completed', 'data' => ['object' => $this->paidSession($invoice)]]);
        $this->hook($b, $s)->assertOk();
        $payment = Payment::first();
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded'])]);

        $refund = app(PaymentService::class)->refund($payment, 1000, 'goodwill');

        $this->assertSame('re_1', $refund->reference);
        Http::assertSent(function ($r) {
            parse_str($r->body(), $b);

            return $r->url() === 'https://api.stripe.com/v1/refunds' && $b['payment_intent'] === 'pi_1' && $b['amount'] === '1000';
        });
        $this->assertSame(1500, $invoice->refresh()->amount_paid);

        [$b, $s] = $this->signed(['type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_1', 'payment_intent' => 'pi_1', 'amount_refunded' => 1000, 'refunds' => ['data' => [['id' => 're_1', 'amount' => 1000, 'status' => 'succeeded']]]]]]);
        $this->hook($b, $s)->assertOk();
        [$b, $s] = $this->signed(['type' => 'refund.updated', 'data' => ['object' => ['id' => 're_1', 'payment_intent' => 'pi_1', 'amount' => 1000, 'status' => 'succeeded']]]);
        $this->hook($b, $s)->assertOk();

        $this->assertSame(1, Payment::where('type', 'refund')->count());
        $this->assertSame(1500, $invoice->refresh()->amount_paid);
    }

    public function test_dashboard_refund_arrives_by_webhook(): void
    {
        $invoice = $this->invoice();
        [$b, $s] = $this->signed(['type' => 'checkout.session.completed', 'data' => ['object' => $this->paidSession($invoice)]]);
        $this->hook($b, $s);

        [$b, $s] = $this->signed(['type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_1', 'payment_intent' => 'pi_1', 'amount_refunded' => 500]]]);
        $this->hook($b, $s)->assertOk();
        $this->hook($b, $s)->assertOk();

        $this->assertSame(1, Payment::where('type', 'refund')->count());
        $this->assertSame(2000, $invoice->refresh()->amount_paid);
    }

    public function test_unconfigured_gateway_is_not_offered(): void
    {
        Settings::forget('gateway.stripe.webhook_secret');
        $this->assertArrayNotHasKey('stripe', app(GatewayRegistry::class)->available('USD'));
    }

    public function test_configured_gateway_is_offered(): void
    {
        $this->assertArrayHasKey('stripe', app(GatewayRegistry::class)->available('USD'));
    }
}
