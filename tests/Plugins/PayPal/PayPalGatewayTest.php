<?php

namespace Tests\Plugins\PayPal;

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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayPalGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api-m.sandbox.paypal.com';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->enablePlugin('paypal');
        Settings::set('gateway.paypal.client_id', 'cid');
        Settings::set('gateway.paypal.client_secret', 'sec', true);
        Settings::set('gateway.paypal.webhook_id', 'WH-1');
        Settings::set('gateway.paypal.enabled', true);
    }

    private function invoice(int $price = 2500, string $currency = 'USD'): Invoice
    {
        $client = Client::factory()->create(['currency' => $currency]);

        return app(InvoiceService::class)->create($client, [['description' => 'Hosting', 'unit_price' => $price, 'taxable' => false]], ['issue' => true, 'currency' => $currency]);
    }

    private function gateway()
    {
        return app(GatewayRegistry::class)->get('paypal');
    }

    private function auth(): array
    {
        return [self::BASE.'/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600])];
    }

    private function capture(Invoice $invoice, string $id = 'CAP1'): array
    {
        return ['id' => $id, 'status' => 'COMPLETED', 'custom_id' => $invoice->uuid, 'amount' => ['value' => number_format($invoice->total / 100, 2, '.', ''), 'currency_code' => 'USD'], 'seller_receivable_breakdown' => ['paypal_fee' => ['value' => '1.10']]];
    }

    private function order(Invoice $invoice): array
    {
        return ['id' => 'ORD1', 'status' => 'COMPLETED', 'purchase_units' => [['custom_id' => $invoice->uuid, 'payments' => ['captures' => [$this->capture($invoice)]]]]];
    }

    private function hook(array $event, string $verify = 'SUCCESS')
    {
        Http::fake($this->auth() + [self::BASE.'/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => $verify])]);

        return $this->call('POST', '/webhooks/gateways/paypal', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA', 'HTTP_PAYPAL_CERT_URL' => 'https://api.paypal.com/cert',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'tid', 'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig', 'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-01-01T00:00:00Z',
        ], json_encode($event));
    }

    public function test_checkout_creates_order_and_redirects_to_approval(): void
    {
        $invoice = $this->invoice();
        Http::fake($this->auth() + [self::BASE.'/v2/checkout/orders' => Http::response(['id' => 'ORD1', 'links' => [['rel' => 'self', 'href' => 'x'], ['rel' => 'payer-action', 'href' => 'https://paypal.test/approve']]])]);

        $result = $this->gateway()->checkout($invoice, new CheckoutContext('https://app.test/ok', 'https://app.test/no', 'https://app.test/wh'));

        $this->assertSame('https://paypal.test/approve', $result->redirectUrl);
        Http::assertSent(function ($r) use ($invoice) {
            if ($r->url() !== self::BASE.'/v2/checkout/orders') {
                return false;
            }
            $b = $r->data();

            return $r->hasHeader('Authorization', 'Bearer tok') && $b['intent'] === 'CAPTURE'
                && $b['purchase_units'][0]['custom_id'] === $invoice->uuid
                && $b['purchase_units'][0]['amount'] === ['currency_code' => 'USD', 'value' => '25.00']
                && $b['payment_source']['paypal']['experience_context']['return_url'] === 'https://app.test/ok'
                && $b['payment_source']['paypal']['experience_context']['user_action'] === 'PAY_NOW';
        });
    }

    public function test_complete_captures_and_records_once(): void
    {
        $invoice = $this->invoice();
        Http::fake($this->auth() + [self::BASE.'/v2/checkout/orders/ORD1/capture' => Http::response($this->order($invoice), 201)]);
        $req = Request::create('/x', 'GET', ['token' => 'ORD1', 'PayerID' => 'P']);

        $a = $this->gateway()->complete($req, $invoice);
        $b = $this->gateway()->complete($req, $invoice);

        $this->assertTrue($a->is($b));
        $this->assertSame('CAP1', $a->reference);
        $this->assertSame(110, $a->fee);
        $this->assertSame(1, Payment::count());
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
    }

    public function test_already_captured_order_is_fetched(): void
    {
        $invoice = $this->invoice();
        Http::fake($this->auth() + [
            self::BASE.'/v2/checkout/orders/ORD1/capture' => Http::response(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]], 422),
            self::BASE.'/v2/checkout/orders/ORD1' => Http::response($this->order($invoice)),
        ]);

        $this->assertNotNull($this->gateway()->complete(Request::create('/x', 'GET', ['token' => 'ORD1']), $invoice));
        $this->assertSame(1, Payment::count());
    }

    public function test_capture_for_another_invoice_is_rejected(): void
    {
        $invoice = $this->invoice();
        $other = $this->invoice();
        Http::fake($this->auth() + [self::BASE.'/v2/checkout/orders/ORD1/capture' => Http::response($this->order($other), 201)]);

        $this->assertNull($this->gateway()->complete(Request::create('/x', 'GET', ['token' => 'ORD1']), $invoice));
        $this->assertSame(0, Payment::count());
    }

    public function test_webhook_capture_completed_records_and_dedupes_with_complete(): void
    {
        $invoice = $this->invoice();
        $event = ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => $this->capture($invoice)];

        $this->hook($event)->assertOk();
        $this->hook($event)->assertOk();
        Http::fake($this->auth() + [self::BASE.'/v2/checkout/orders/ORD1/capture' => Http::response($this->order($invoice), 201)]);
        $this->gateway()->complete(Request::create('/x', 'GET', ['token' => 'ORD1']), $invoice);

        $this->assertSame(1, Payment::count());
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
    }

    public function test_webhook_verification_failure_is_rejected(): void
    {
        $invoice = $this->invoice();
        $this->hook(['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => $this->capture($invoice)], 'FAILURE')->assertStatus(400);

        $this->assertSame(0, Payment::count());
    }

    public function test_webhook_without_signature_headers_is_rejected(): void
    {
        Http::fake($this->auth());
        $this->call('POST', '/webhooks/gateways/paypal', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"event_type":"X"}')->assertStatus(400);
    }

    public function test_refund_request_and_webhook_record_once(): void
    {
        $invoice = $this->invoice();
        $this->hook(['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => $this->capture($invoice)]);
        $payment = Payment::first();
        Http::fake($this->auth() + [self::BASE.'/v2/payments/captures/CAP1/refund' => Http::response(['id' => 'REF1', 'status' => 'COMPLETED'], 201)]);

        $refund = app(PaymentService::class)->refund($payment, 1000);

        $this->assertSame('REF1', $refund->reference);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/captures/CAP1/refund') && $r->data()['amount'] === ['value' => '10.00', 'currency_code' => 'USD']);

        $event = ['event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => ['id' => 'REF1', 'amount' => ['value' => '10.00', 'currency_code' => 'USD'], 'links' => [['rel' => 'up', 'href' => self::BASE.'/v2/payments/captures/CAP1']]]];
        $this->hook($event)->assertOk();

        $this->assertSame(1, Payment::where('type', 'refund')->count());
        $this->assertSame(1500, $invoice->refresh()->amount_paid);
    }

    public function test_currency_support_and_availability(): void
    {
        $this->assertTrue($this->gateway()->supportsCurrency('EUR'));
        $this->assertFalse($this->gateway()->supportsCurrency('INR'));
        $this->assertArrayHasKey('paypal', app(GatewayRegistry::class)->available('USD'));
        $this->assertArrayNotHasKey('paypal', app(GatewayRegistry::class)->available('INR'));

        Settings::forget('gateway.paypal.client_secret');
        $this->assertArrayNotHasKey('paypal', app(GatewayRegistry::class)->available('USD'));
    }
}
