<?php

namespace Tests\Plugins\Stripe;

use App\Enums\InvoiceStatus;
use App\Mail\BillingMail;
use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Plugins\GatewayRegistry;
use App\Services\AutoPayer;
use App\Services\InvoiceService;
use App\Services\PaymentMethods;
use App\Support\ChargeResult;
use App\Support\CheckoutContext;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StripeSavedMethodsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->enablePlugin('stripe');
        Settings::set('gateway.stripe.secret_key', 'sk_test_123', true);
        Settings::set('gateway.stripe.webhook_secret', self::SECRET, true);
        Settings::set('gateway.stripe.enabled', true);
    }

    private function gateway()
    {
        return app(GatewayRegistry::class)->get('stripe');
    }

    private function ctx(string $success = 'https://app.test/portal/payment-methods/return/stripe?autopay=1'): CheckoutContext
    {
        return new CheckoutContext($success, 'https://app.test/portal/payment-methods', 'https://app.test/webhooks/gateways/stripe', 'a@b.test');
    }

    private function saved(Client $client, string $id = 'pm_1'): PaymentMethod
    {
        return PaymentMethod::create(['client_id' => $client->id, 'gateway' => 'stripe', 'reference' => $id, 'customer_reference' => 'cus_1', 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2099, 'is_default' => true, 'autopay' => true]);
    }

    private function hook(array $event)
    {
        $body = json_encode($event);
        $t = time();

        return $this->call('POST', '/webhooks/gateways/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t=$t,v1=".hash_hmac('sha256', $t.'.'.$body, self::SECRET), 'CONTENT_TYPE' => 'application/json'], $body);
    }

    private function sessionBody(Client $client, string $pm = 'pm_new'): array
    {
        return ['id' => 'cs_setup', 'mode' => 'setup', 'status' => 'complete', 'customer' => 'cus_1', 'metadata' => ['client_uuid' => $client->uuid, 'autopay' => '1'],
            'setup_intent' => ['payment_method' => ['id' => $pm, 'type' => 'card', 'customer' => 'cus_1', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 5, 'exp_year' => 2031]]]];
    }

    public function test_begin_setup_creates_customer_once_and_setup_session(): void
    {
        $client = Client::factory()->create(['currency' => 'EUR']);
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_9']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/1']),
        ]);

        $r = $this->gateway()->beginSetup($client, $this->ctx());
        $this->assertSame('https://checkout.stripe.com/c/1', $r->redirectUrl);
        $this->gateway()->beginSetup($client, $this->ctx());

        Http::assertSentCount(3); // customer once, two sessions
        Http::assertSent(function ($req) use ($client) {
            if (! str_ends_with($req->url(), '/checkout/sessions')) {
                return false;
            }
            parse_str($req->body(), $b);

            return $b['mode'] === 'setup' && $b['customer'] === 'cus_9' && $b['currency'] === 'eur' && $b['metadata']['client_uuid'] === $client->uuid
                && str_contains($b['success_url'], 'session_id={CHECKOUT_SESSION_ID}') && $b['metadata']['autopay'] === '1';
        });
    }

    public function test_complete_setup_returns_method_and_rejects_other_clients_session(): void
    {
        $client = Client::factory()->create();
        Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response($this->sessionBody($client))]);
        $req = Request::create('/x', 'GET', ['session_id' => 'cs_setup']);

        $data = $this->gateway()->completeSetup($req, $client);
        $this->assertSame('pm_new', $data->reference);
        $this->assertSame('cus_1', $data->customerReference);
        $this->assertSame('4242', $data->last4);
        $this->assertSame(2031, $data->expYear);

        $this->assertNull($this->gateway()->completeSetup($req, Client::factory()->create()));
    }

    public function test_charge_success_sends_off_session_with_idempotency_key(): void
    {
        $client = Client::factory()->create();
        $method = $this->saved($client);
        $invoice = app(InvoiceService::class)->create($client, [['description' => 'H', 'unit_price' => 2500, 'taxable' => false]], ['issue' => true, 'due_date' => now()->addDays(9)->toDateString()]);
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_ok', 'status' => 'succeeded', 'amount_received' => 2500, 'latest_charge' => 'ch_1'])]);

        $result = app(PaymentMethods::class)->pay($method, $invoice, 'key-1');

        $this->assertTrue($result->succeeded());
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame('pi_ok', Payment::where('invoice_id', $invoice->id)->value('reference'));
        Http::assertSent(function ($req) {
            parse_str($req->body(), $b);

            return $req->hasHeader('Idempotency-Key', 'key-1') && $b['off_session'] === 'true' && $b['confirm'] === 'true' && $b['payment_method'] === 'pm_1'
                && $b['customer'] === 'cus_1' && (int) $b['amount'] === 2500 && $b['currency'] === 'usd';
        });
    }

    public function test_charge_authentication_required_and_decline(): void
    {
        $client = Client::factory()->create();
        $method = $this->saved($client);
        $invoice = app(InvoiceService::class)->create($client, [['description' => 'H', 'unit_price' => 1000, 'taxable' => false]], ['issue' => true]);

        Http::fake(['api.stripe.com/v1/payment_intents' => Http::sequence()
            ->push(['error' => ['code' => 'authentication_required', 'message' => 'Auth', 'payment_intent' => ['id' => 'pi_a']]], 402)
            ->push(['error' => ['code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'message' => 'Your card has insufficient funds.']], 402)
            ->push(['id' => 'pi_p', 'status' => 'processing'])]);

        $r = $this->gateway()->charge($method, $invoice, 'k');
        $this->assertSame(ChargeResult::REQUIRES_ACTION, $r->status);
        $this->assertSame('pi_a', $r->reference);
        $r = $this->gateway()->charge($method, $invoice, 'k2');
        $this->assertSame(ChargeResult::FAILED, $r->status);
        $this->assertSame('Your card has insufficient funds.', $r->message);
        $this->assertSame(ChargeResult::PENDING, $this->gateway()->charge($method, $invoice, 'k3')->status);
    }

    public function test_autopay_end_to_end_with_stripe_failure_emails_customer(): void
    {
        $client = Client::factory()->create();
        $this->saved($client);
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['error' => ['code' => 'card_declined', 'message' => 'Declined']], 402)]);

        $invoice = app(InvoiceService::class)->create($client, [['description' => 'H', 'unit_price' => 1000, 'taxable' => false]], ['issue' => true, 'due_date' => now()->toDateString()]);

        $this->assertSame(InvoiceStatus::Open, $invoice->refresh()->status);
        Mail::assertSent(BillingMail::class, fn ($m) => str_contains($m->mailSubject, 'Payment failed'));
        $this->assertSame(1, app(AutoPayer::class)->run()['checked']);
    }

    public function test_delete_detaches(): void
    {
        $method = $this->saved(Client::factory()->create());
        Http::fake(['api.stripe.com/v1/payment_methods/pm_1/detach' => Http::response(['id' => 'pm_1'])]);

        $this->assertTrue(app(PaymentMethods::class)->remove($method));
        $this->assertTrue($method->refresh()->isRemoved());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/payment_methods/pm_1/detach'));
    }

    public function test_webhook_setup_session_stores_method(): void
    {
        $client = Client::factory()->create();
        Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response($this->sessionBody($client))]);

        $this->hook(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_setup', 'mode' => 'setup', 'metadata' => ['client_uuid' => $client->uuid, 'autopay' => '1']]]])->assertOk();
        $this->hook(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_setup', 'mode' => 'setup', 'metadata' => ['client_uuid' => $client->uuid, 'autopay' => '1']]]])->assertOk();

        $m = PaymentMethod::where('client_id', $client->id)->sole();
        $this->assertSame('pm_new', $m->reference);
        $this->assertTrue($m->autopay);
        $this->assertTrue($m->is_default);
    }

    public function test_webhook_detached_and_updated(): void
    {
        $client = Client::factory()->create();
        $m = $this->saved($client);

        $this->hook(['type' => 'payment_method.updated', 'data' => ['object' => ['id' => 'pm_1', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '1111', 'exp_month' => 1, 'exp_year' => 2040]]]])->assertOk();
        $this->assertSame('1111', $m->refresh()->last4);
        $this->assertSame(2040, $m->exp_year);

        $this->hook(['type' => 'payment_method.detached', 'data' => ['object' => ['id' => 'pm_1']]])->assertOk();
        $this->assertTrue($m->refresh()->isRemoved());
        $this->assertFalse($m->autopay);
    }

    public function test_webhook_records_autopay_payment_intent_idempotently(): void
    {
        $client = Client::factory()->create();
        $invoice = app(InvoiceService::class)->create($client, [['description' => 'H', 'unit_price' => 1000, 'taxable' => false]], ['issue' => true]);
        $event = ['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_w', 'amount' => 1000, 'amount_received' => 1000, 'metadata' => ['autopay' => '1', 'invoice_uuid' => $invoice->uuid]]]];

        $this->hook($event)->assertOk();
        $this->hook($event)->assertOk();
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
    }
}
