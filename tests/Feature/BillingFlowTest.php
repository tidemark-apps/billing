<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Product;
use App\Models\TaxRate;
use App\Plugins\ModuleRegistry;
use App\Services\BillingRunner;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeModule;
use Tests\TestCase;

class BillingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_ordering_and_paying_provisions_the_service(): void
    {
        app(ModuleRegistry::class)->register(new FakeModule);
        $client = Client::factory()->create();
        $product = Product::factory()->priced(1500, BillingCycle::Monthly, 500)->module('fake')->create();
        TaxRate::create(['name' => 'VAT', 'rate' => 10, 'country' => 'US']);

        ['service' => $service, 'invoice' => $invoice] = app(OrderService::class)->place($client, $product, BillingCycle::Monthly, ['label' => 'example.com']);

        $this->assertSame(ServiceStatus::Pending, $service->status);
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertSame(2000, $invoice->subtotal);
        $this->assertSame(200, $invoice->tax_total);
        $this->assertSame(2200, $invoice->total);
        $this->assertStringStartsWith('INV-', $invoice->number);

        $payment = app(PaymentService::class)->record($invoice, 'test', 2200, 'tx_1');
        // recording the same gateway reference again must not double-count
        $this->assertTrue($payment->is(app(PaymentService::class)->record($invoice, 'test', 2200, 'tx_1')));

        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame('fake-account', $service->data('username'));
        $this->assertSame('s3cret', $service->secret('password'));
        $this->assertNotNull($service->next_due_date);
    }

    public function test_renewal_invoice_is_generated_once_and_paying_it_extends_the_service(): void
    {
        $client = Client::factory()->create();
        $product = Product::factory()->priced(1000)->create();
        ['service' => $service, 'invoice' => $first] = app(OrderService::class)->place($client, $product, BillingCycle::Monthly);
        app(PaymentService::class)->record($first, 'test', $first->total, 'a');
        $service->refresh();
        $due = $service->next_due_date->copy();

        $this->travelTo($due->copy()->subDays(5));
        $this->assertSame(1, app(BillingRunner::class)->generateRenewalInvoices());
        $this->assertSame(0, app(BillingRunner::class)->generateRenewalInvoices(), 'must be idempotent');

        $renewal = $service->invoiceItems()->where('kind', 'renewal')->first()->invoice;
        app(PaymentService::class)->record($renewal, 'test', $renewal->total, 'b');

        $this->assertTrue($service->refresh()->next_due_date->gt($due));
    }

    public function test_unpaid_renewal_suspends_then_payment_unsuspends(): void
    {
        $client = Client::factory()->create();
        $product = Product::factory()->priced(1000)->create();
        ['service' => $service, 'invoice' => $first] = app(OrderService::class)->place($client, $product, BillingCycle::Monthly);
        app(PaymentService::class)->record($first, 'test', $first->total, 'a');
        $due = $service->refresh()->next_due_date->copy();

        $this->travelTo($due->copy()->subDays(5));
        app(BillingRunner::class)->generateRenewalInvoices();

        $this->travelTo($due->copy()->addDays(6));
        app(BillingRunner::class)->suspendOverdue();
        $this->assertSame(ServiceStatus::Suspended, $service->refresh()->status);

        $renewal = $service->invoiceItems()->where('kind', 'renewal')->first()->invoice;
        app(PaymentService::class)->record($renewal, 'test', $renewal->total, 'late');
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
    }

    public function test_refund_reduces_net_paid(): void
    {
        $client = Client::factory()->create();
        $product = Product::factory()->priced(1000)->create();
        ['invoice' => $invoice] = app(OrderService::class)->place($client, $product, BillingCycle::Monthly);
        $payment = app(PaymentService::class)->recordManual($invoice, $invoice->total, 'bank transfer');

        app(PaymentService::class)->refund($payment, 400, 'goodwill');

        $this->assertSame(600, $invoice->refresh()->amount_paid);
        $this->assertSame(400, $payment->refundedAmount());
    }
}
