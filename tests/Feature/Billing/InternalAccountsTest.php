<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\User;
use App\Services\BillingRunner;
use App\Services\InternalAccounts;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PlanChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function paidService(Client $client, int $price = 5000, int $setup = 0): Service
    {
        $product = Product::factory()->priced($price, BillingCycle::Monthly, $setup)->create();
        ['service' => $service, 'invoice' => $invoice] = app(OrderService::class)->place($client, $product, BillingCycle::Monthly);
        if ($invoice && $invoice->total > 0) {
            app(PaymentService::class)->record($invoice, 'test', $invoice->total, 'p'.$service->id);
        }

        return $service->refresh();
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_marking_a_client_internal_zeroes_their_services_and_unmarking_restores_the_prices(): void
    {
        $client = Client::factory()->create();
        $a = $this->paidService($client, 5000);
        $b = $this->paidService($client, 1200);

        app(InternalAccounts::class)->set($client, true, $this->staff());

        $this->assertTrue($client->refresh()->is_internal);
        $this->assertSame([0, 0], [$a->refresh()->amount, $b->refresh()->amount]);
        $this->assertSame([5000, 1200], [$a->internal_original_amount, $b->internal_original_amount]);

        // marking again must not overwrite the remembered prices with zero
        app(InternalAccounts::class)->set($client, true);
        $this->assertSame(5000, $a->refresh()->internal_original_amount);

        app(InternalAccounts::class)->set($client, false, $this->staff());

        $this->assertFalse($client->refresh()->is_internal);
        $this->assertSame([5000, 1200], [$a->refresh()->amount, $b->refresh()->amount]);
        $this->assertNull($a->internal_original_amount);
    }

    public function test_new_orders_for_an_internal_client_are_free_and_provision_without_payment(): void
    {
        $client = Client::factory()->create(['is_internal' => true]);
        $product = Product::factory()->priced(5000, BillingCycle::Monthly, 1000)->create();

        ['service' => $service, 'invoice' => $invoice] = app(OrderService::class)->place($client, $product, BillingCycle::Monthly);

        $this->assertSame(0, $service->amount);
        $this->assertSame(5000, $service->internal_original_amount);
        $this->assertSame(0, $invoice->total, 'no price and no setup fee');
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status, 'a free invoice settles itself');
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);

        app(InternalAccounts::class)->set($client, false);
        $this->assertSame(5000, $service->refresh()->amount, 'ending internal status restores the real price');
    }

    public function test_renewals_of_internal_services_are_free(): void
    {
        $client = Client::factory()->create();
        $service = $this->paidService($client, 5000);
        app(InternalAccounts::class)->set($client, true);
        $due = $service->refresh()->next_due_date->copy();

        $this->travelTo($due->copy()->subDays(5));
        app(BillingRunner::class)->generateRenewalInvoices();

        $invoice = $service->invoiceItems()->where('kind', 'renewal')->latest('id')->first()->invoice;
        $this->assertSame(0, $invoice->total);
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertTrue($service->refresh()->next_due_date->gt($due));
    }

    public function test_plan_changes_for_internal_clients_cost_nothing_and_remember_the_list_price(): void
    {
        $group = ProductGroup::create(['name' => 'Hosting', 'slug' => 'hosting']);
        $basic = Product::factory()->priced(3000)->create(['product_group_id' => $group->id]);
        $pro = Product::factory()->priced(6000)->create(['product_group_id' => $group->id]);
        $client = Client::factory()->create(['is_internal' => true]);
        ['service' => $service] = app(OrderService::class)->place($client, $basic, BillingCycle::Monthly);
        $service->refresh()->update(['status' => ServiceStatus::Active, 'next_due_date' => now()->addDays(10)]);

        $change = app(PlanChangeService::class)->change($service->refresh(), $pro, BillingCycle::Monthly);

        $this->assertNull($change->invoice_id);
        $this->assertSame(0, $service->refresh()->amount);
        $this->assertSame(6000, $service->internal_original_amount);
        $this->assertSame($pro->id, $service->product_id);
    }

    public function test_internal_clients_are_excluded_from_the_dashboard_numbers(): void
    {
        $real = Client::factory()->create();
        $internal = Client::factory()->create();
        $this->paidService($real, 4000);
        $this->paidService($internal, 9000);
        app(InternalAccounts::class)->set($internal, true);
        $before = Payment::count();
        $this->assertSame(2, $before);

        $response = $this->actingAs($this->staff())->get('/admin');

        $response->assertOk();
        $this->assertSame(4000, $response->viewData('revenue'), 'only the real customer\'s payment counts');
        $this->assertSame(1, $response->viewData('activeServices'));
        $this->assertSame(1, $response->viewData('newClients'));
        $this->assertSame(9000, (int) Payment::where('client_id', $internal->id)->sum('amount'), 'the data itself is untouched');
        $this->assertSame(1, Invoice::external()->where('status', InvoiceStatus::Paid->value)->count());
    }

    public function test_the_admin_form_marks_and_unmarks_a_client(): void
    {
        $admin = $this->staff();
        $client = Client::factory()->create(['currency' => 'USD', 'status' => 'active']);
        $service = $this->paidService($client, 2500);
        $payload = fn (bool $internal) => [
            'name' => $client->name, 'email' => $client->email, 'currency' => 'USD', 'status' => 'active', 'is_internal' => $internal ? 1 : 0,
        ];

        $this->actingAs($admin)->put(route('admin.clients.update', $client), $payload(true))->assertRedirect();
        $this->assertTrue($client->refresh()->is_internal);
        $this->assertSame(0, $service->refresh()->amount);

        $this->actingAs($admin)->get(route('admin.clients.show', $client))->assertOk()->assertSee('Internal');

        $this->actingAs($admin)->put(route('admin.clients.update', $client), $payload(false))->assertRedirect();
        $this->assertFalse($client->refresh()->is_internal);
        $this->assertSame(2500, $service->refresh()->amount);
    }

    public function test_editing_an_internal_services_price_keeps_it_free(): void
    {
        $admin = $this->staff();
        $client = Client::factory()->create(['is_internal' => true]);
        $service = $this->paidService($client, 2500);

        $this->actingAs($admin)->put(route('admin.services.update', $service), [
            'label' => 'x', 'amount' => '0.00', 'billing_cycle' => 'monthly', 'next_due_date' => $service->next_due_date->toDateString(),
        ])->assertRedirect();
        $this->assertSame(0, $service->refresh()->amount);
        $this->assertSame(2500, $service->internal_original_amount, 'saving the form (which shows 0) must not forget the real price');

        $this->actingAs($admin)->put(route('admin.services.update', $service), [
            'label' => 'x', 'amount' => '40.00', 'billing_cycle' => 'monthly', 'next_due_date' => $service->next_due_date->toDateString(),
        ])->assertRedirect();
        $this->assertSame(0, $service->refresh()->amount);
        $this->assertSame(4000, $service->internal_original_amount, 'a typed price becomes the price they would pay');
    }
}
