<?php

namespace Tests\Plugins\PayPal;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Plugins\GatewayRegistry;
use App\Services\InvoiceService;
use App\Services\PaymentMethods;
use App\Support\ChargeResult;
use App\Support\CheckoutContext;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PayPalSavedMethodsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api-m.sandbox.paypal.com';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        $this->enablePlugin('paypal');
        Settings::set('gateway.paypal.client_id', 'cid');
        Settings::set('gateway.paypal.client_secret', 'sec', true);
        Settings::set('gateway.paypal.webhook_id', 'WH-1');
        Settings::set('gateway.paypal.enabled', true);
    }

    private function gateway()
    {
        return app(GatewayRegistry::class)->get('paypal');
    }

    private function auth(): array
    {
        return [self::BASE.'/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600])];
    }

    private function saved(Client $client, string $type = 'paypal'): PaymentMethod
    {
        return PaymentMethod::create(['client_id' => $client->id, 'gateway' => 'paypal', 'reference' => 'vault1', 'customer_reference' => 'cust1', 'brand' => $type, 'is_default' => true, 'autopay' => true, 'data' => ['type' => $type]]);
    }

    private function invoice(Client $client)
    {
        return app(InvoiceService::class)->create($client, [['description' => 'H', 'unit_price' => 2500, 'taxable' => false]], ['issue' => true, 'due_date' => now()->addDays(9)->toDateString()]);
    }

    public function test_setup_flow_setup_token_then_payment_token(): void
    {
        $client = Client::factory()->create();
        Http::fake($this->auth() + [
            self::BASE.'/v3/vault/setup-tokens' => Http::response(['id' => 'ST1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [['rel' => 'self', 'href' => 'x'], ['rel' => 'approve', 'href' => 'https://sandbox.paypal.com/agreements/approve?approval_session_id=ST1']]], 201),
            self::BASE.'/v3/vault/payment-tokens' => Http::response(['id' => 'vault1', 'customer' => ['id' => 'cust1'], 'payment_source' => ['paypal' => ['email_address' => 'me@pay.test', 'payer_id' => 'P']]], 201),
        ]);
        $ctx = new CheckoutContext('https://app.test/return', 'https://app.test/cancel', 'https://app.test/wh', 'a@b.test');

        $r = $this->gateway()->beginSetup($client, $ctx);
        $this->assertStringContainsString('agreements/approve', $r->redirectUrl);
        Http::assertSent(fn ($q) => str_ends_with($q->url(), '/v3/vault/setup-tokens') && $q['payment_source']['paypal']['usage_type'] === 'MERCHANT' && $q['payment_source']['paypal']['experience_context']['return_url'] === 'https://app.test/return');

        $data = $this->gateway()->completeSetup(Request::create('/x'), $client);
        $this->assertSame('vault1', $data->reference);
        $this->assertSame('cust1', $data->customerReference);
        $this->assertSame('me@pay.test', $data->label);
        Http::assertSent(fn ($q) => str_ends_with($q->url(), '/v3/vault/payment-tokens') && $q['payment_source']['token'] === ['id' => 'ST1', 'type' => 'SETUP_TOKEN']);

        // the setup token is single use: a second return finds nothing
        $this->assertNull($this->gateway()->completeSetup(Request::create('/x'), $client));
    }

    public function test_charge_with_vault_id_captures_and_records(): void
    {
        $client = Client::factory()->create();
        $method = $this->saved($client);
        $invoice = $this->invoice($client);
        Http::fake($this->auth() + [self::BASE.'/v2/checkout/orders' => Http::response([
            'id' => 'O1', 'status' => 'COMPLETED',
            'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP9', 'status' => 'COMPLETED', 'amount' => ['value' => '25.00', 'currency_code' => 'USD'], 'seller_receivable_breakdown' => ['paypal_fee' => ['value' => '1.10']]]]]]],
        ], 201)]);

        $res = app(PaymentMethods::class)->pay($method, $invoice, 'req-1');

        $this->assertTrue($res->succeeded());
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $p = Payment::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('CAP9', $p->reference);
        $this->assertSame(110, $p->fee);
        Http::assertSent(fn ($q) => str_ends_with($q->url(), '/v2/checkout/orders') && $q->hasHeader('PayPal-Request-Id', 'req-1')
            && $q['intent'] === 'CAPTURE' && $q['payment_source']['paypal']['vault_id'] === 'vault1' && $q['purchase_units'][0]['amount']['value'] === '25.00');
    }

    public function test_charge_card_token_uses_card_source_and_failures_map(): void
    {
        $client = Client::factory()->create();
        $method = $this->saved($client, 'card');
        $invoice = $this->invoice($client);
        Http::fake($this->auth() + [self::BASE.'/v2/checkout/orders' => Http::sequence()
            ->push(['name' => 'UNPROCESSABLE_ENTITY', 'message' => 'x', 'details' => [['issue' => 'INSTRUMENT_DECLINED', 'description' => 'The instrument presented was declined.']]], 422)
            ->push(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'PAYER_ACTION_REQUIRED']]], 422)
            ->push(['id' => 'O2', 'status' => 'PAYER_ACTION_REQUIRED'], 200)]);

        $r = $this->gateway()->charge($method, $invoice, 'k1');
        $this->assertSame(ChargeResult::FAILED, $r->status);
        $this->assertSame('The instrument presented was declined.', $r->message);
        $this->assertSame(ChargeResult::REQUIRES_ACTION, $this->gateway()->charge($method, $invoice, 'k2')->status);
        $this->assertSame(ChargeResult::REQUIRES_ACTION, $this->gateway()->charge($method, $invoice, 'k3')->status);
        Http::assertSent(fn ($q) => isset($q['payment_source']['card']['vault_id']) && $q['payment_source']['card']['stored_credential']['payment_initiator'] === 'MERCHANT');
    }

    public function test_delete_token(): void
    {
        $method = $this->saved(Client::factory()->create());
        Http::fake($this->auth() + [self::BASE.'/v3/vault/payment-tokens/vault1' => Http::response('', 204)]);

        $this->assertTrue(app(PaymentMethods::class)->remove($method));
        $this->assertTrue($method->refresh()->isRemoved());
        Http::assertSent(fn ($q) => $q->method() === 'DELETE' && str_ends_with($q->url(), '/v3/vault/payment-tokens/vault1'));
    }

    public function test_webhook_token_deleted_marks_removed(): void
    {
        $m = $this->saved(Client::factory()->create());
        Http::fake($this->auth() + [self::BASE.'/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'])]);

        $this->call('POST', '/webhooks/gateways/paypal', [], [], [], [
            'HTTP_PAYPAL_AUTH_ALGO' => 'a', 'HTTP_PAYPAL_CERT_URL' => 'c', 'HTTP_PAYPAL_TRANSMISSION_ID' => 't', 'HTTP_PAYPAL_TRANSMISSION_SIG' => 's', 'HTTP_PAYPAL_TRANSMISSION_TIME' => 'tt', 'CONTENT_TYPE' => 'application/json',
        ], json_encode(['event_type' => 'VAULT.PAYMENT-TOKEN.DELETED', 'resource' => ['id' => 'vault1']]))->assertOk();

        $this->assertTrue($m->refresh()->isRemoved());
    }
}
