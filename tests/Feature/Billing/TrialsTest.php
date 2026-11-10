<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Events\TrialConverted;
use App\Events\TrialEnding;
use App\Events\TrialStarted;
use App\Models\Client;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\BillingRunner;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TrialsTest extends TestCase
{
    use RefreshDatabase;

    private function trialProduct(int $days = 14, int $setup = 0): Product
    {
        return Product::factory()->priced(2000, BillingCycle::Monthly, $setup)->create(['trial_days' => $days]);
    }

    public function test_a_trial_provisions_immediately_without_an_invoice(): void
    {
        Event::fake([TrialStarted::class]);
        $client = Client::factory()->create();
        $product = $this->trialProduct();

        ['service' => $service, 'invoice' => $invoice] = app(OrderService::class)->place($client, $product, BillingCycle::Monthly);

        $this->assertNull($invoice);
        $this->assertSame(0, $client->invoices()->count());
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertTrue($service->onTrial());
        $this->assertSame(now()->addDays(14)->toDateString(), $service->trial_ends_at->toDateString());
        $this->assertSame($service->trial_ends_at->toDateString(), $service->next_due_date->toDateString());
        Event::assertDispatched(TrialStarted::class);
    }

    public function test_trials_are_once_per_customer_per_product(): void
    {
        $client = Client::factory()->create();
        $product = $this->trialProduct();
        $orders = app(OrderService::class);

        $first = $orders->place($client, $product, BillingCycle::Monthly);
        $orders->place($client, $product, BillingCycle::Monthly); // the second order is billed normally
        $this->assertNull($first['invoice']);
        $this->assertSame(1, $client->invoices()->count());

        app(ProvisioningService::class)->cancel($first['service']->refresh());
        $third = $orders->place($client, $product, BillingCycle::Monthly);
        $this->assertNotNull($third['invoice'], 'a cancelled trial does not give a new one');
    }

    public function test_trial_opt_out_is_billed_straight_away(): void
    {
        $client = Client::factory()->create();

        $order = app(OrderService::class)->place($client, $this->trialProduct(), BillingCycle::Monthly, ['trial' => false]);

        $this->assertNotNull($order['invoice']);
        $this->assertFalse($order['service']->onTrial());
    }

    public function test_a_payment_method_can_be_required_to_start_a_trial(): void
    {
        $client = Client::factory()->create();
        $product = $this->trialProduct();
        $product->update(['trial_requires_payment_method' => true]);

        try {
            app(OrderService::class)->place($client, $product, BillingCycle::Monthly);
            $this->fail('expected a DomainException');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('payment method', $e->getMessage());
        }

        PaymentMethod::create(['client_id' => $client->id, 'gateway' => 'test', 'reference' => 'pm_1', 'brand' => 'Visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2099, 'is_default' => true]);
        $this->assertNull(app(OrderService::class)->place($client, $product, BillingCycle::Monthly)['invoice']);
    }

    public function test_the_first_invoice_is_raised_when_the_trial_ends_with_the_setup_fee(): void
    {
        $client = Client::factory()->create();
        ['service' => $service] = app(OrderService::class)->place($client, $this->trialProduct(14, 500), BillingCycle::Monthly);
        $runner = app(BillingRunner::class);

        $this->travelTo(now()->addDays(11));
        $this->assertSame(0, $runner->generateRenewalInvoices(), 'not invoiced early');
        $this->assertSame(1, $runner->sendTrialReminders(), 'reminded 3 days before');
        $this->assertSame(0, $runner->sendTrialReminders(), 'reminded once');

        $this->travelTo(now()->addDays(3));
        $this->assertSame(1, $runner->generateRenewalInvoices());

        $service->refresh();
        $this->assertFalse($service->onTrial());
        $this->assertNotNull($service->trial_converted_at);
        $invoice = $service->invoiceItems()->where('kind', 'renewal')->first()->invoice;
        $this->assertSame(2500, $invoice->total);
        $this->assertSame(['renewal', 'setup'], $invoice->items->pluck('kind')->sort()->values()->all());

        app(PaymentService::class)->record($invoice, 'test', $invoice->total, 'conv');
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertTrue($service->refresh()->next_due_date->gt(now()));
        $this->assertSame(0, $runner->generateRenewalInvoices());
    }

    public function test_conversion_dispatches_an_event(): void
    {
        $client = Client::factory()->create();
        app(OrderService::class)->place($client, $this->trialProduct(2), BillingCycle::Monthly);
        Event::fake([TrialConverted::class]);

        $this->travelTo(now()->addDays(2));
        app(BillingRunner::class)->generateRenewalInvoices();

        Event::assertDispatched(TrialConverted::class);
    }

    public function test_cancelling_during_a_trial_ends_it_at_once_and_never_bills(): void
    {
        $client = Client::factory()->create();
        ['service' => $service] = app(OrderService::class)->place($client, $this->trialProduct(), BillingCycle::Monthly);

        $service = app(ProvisioningService::class)->cancel($service->refresh());

        $this->assertSame(ServiceStatus::Cancelled, $service->status);
        $this->travelTo(now()->addDays(30));
        $this->assertSame(0, app(BillingRunner::class)->generateRenewalInvoices());
        $this->assertSame(0, $client->invoices()->count());
    }

    public function test_the_ending_reminder_dispatches_an_event(): void
    {
        $client = Client::factory()->create();
        app(OrderService::class)->place($client, $this->trialProduct(2), BillingCycle::Monthly);
        Event::fake([TrialEnding::class]);

        $this->assertSame(1, app(BillingRunner::class)->sendTrialReminders());
        Event::assertDispatched(TrialEnding::class);
    }
}
