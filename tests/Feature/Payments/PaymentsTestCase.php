<?php

namespace Tests\Feature\Payments;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Plugins\GatewayRegistry;
use App\Services\InvoiceService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

abstract class PaymentsTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeSavedGateway::$results = [];
        FakeSavedGateway::$keys = [];
        FakeSavedGateway::$deleteOk = true;
        app(GatewayRegistry::class)->register(new FakeSavedGateway);
        Settings::set('gateway.fakecard.enabled', true);
        Mail::fake();
    }

    protected function method(Client $client, array $attrs = []): PaymentMethod
    {
        return PaymentMethod::create($attrs + [
            'client_id' => $client->id, 'gateway' => 'fakecard', 'reference' => 'tok_'.uniqid(), 'customer_reference' => 'cus_1',
            'brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2099, 'is_default' => true, 'autopay' => true,
        ]);
    }

    protected function invoice(Client $client, int $price = 2500, ?string $due = null): Invoice
    {
        return app(InvoiceService::class)->create($client, [['description' => 'Hosting', 'unit_price' => $price, 'taxable' => false]], [
            'issue' => true, 'currency' => $client->currency, 'due_date' => $due ?? now()->toDateString(),
        ]);
    }
}
