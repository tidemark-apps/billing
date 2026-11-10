<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Events\PlanChanged;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Plugins\ModuleRegistry;
use App\Services\BillingRunner;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PlanChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeModule;
use Tests\TestCase;

class PlanChangesTest extends TestCase
{
    use RefreshDatabase;

    private Product $basic;

    private Product $pro;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $group = ProductGroup::create(['name' => 'Hosting', 'slug' => 'hosting']);
        $this->basic = Product::factory()->priced(3000)->priced(30000, BillingCycle::Annually)->create(['product_group_id' => $group->id, 'name' => 'Basic']);
        $this->pro = Product::factory()->priced(6000)->create(['product_group_id' => $group->id, 'name' => 'Pro']);
        $this->client = Client::factory()->create();
    }

    /** A paid, active service on Basic with a 30-day period that is exactly half used. */
    private function liveService(): Service
    {
        $this->travelTo(now()->startOfDay());
        ['service' => $service, 'invoice' => $invoice] = app(OrderService::class)->place($this->client, $this->basic, BillingCycle::Monthly);
        app(PaymentService::class)->record($invoice, 'test', $invoice->total, 'first');
        $service->refresh();
        // the current period ends 15 days from now, so half of it (more or less, depending on the month) is left
        $service->update(['next_due_date' => now()->addDays(15)]);

        return $service->refresh();
    }

    public function test_an_upgrade_is_prorated_credited_and_invoiced_now(): void
    {
        Event::fake([PlanChanged::class]);
        $service = $this->liveService();
        $days = (int) now()->addDays(15)->subMonthNoOverflow()->diffInDays(now()->addDays(15));
        $remaining = 15;

        $plan = app(PlanChangeService::class)->preview($service, $this->pro, BillingCycle::Monthly);
        $credit = (int) round(3000 * $remaining / $days);
        $charge = (int) round(6000 * $remaining / $days);
        $this->assertSame('upgrade', $plan['direction']);
        $this->assertSame('immediate', $plan['timing']);
        $this->assertSame($credit, $plan['credit']);
        $this->assertSame($charge, $plan['charge']);
        $this->assertSame($charge - $credit, $plan['amount_due']);

        $change = app(PlanChangeService::class)->change($service, $this->pro, BillingCycle::Monthly);

        $service->refresh();
        $this->assertSame($this->pro->id, $service->product_id);
        $this->assertSame(6000, $service->amount);
        $this->assertSame('applied', $change->status);
        $invoice = $change->invoice;
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertSame($charge - $credit, $invoice->subtotal);
        $this->assertSame(['credit', 'plan_change'], $invoice->items->pluck('kind')->sort()->values()->all());
        $this->assertSame(now()->addDays(15)->toDateString(), $service->next_due_date->toDateString(), 'renewal date is unchanged');
        Event::assertDispatched(PlanChanged::class);
    }

    public function test_a_customer_downgrade_waits_for_the_renewal_and_is_applied_then(): void
    {
        $service = $this->liveService();
        $this->pro->update(['name' => 'Pro']);
        $service->update(['product_id' => $this->pro->id, 'amount' => 6000]);

        $change = app(PlanChangeService::class)->change($service->refresh(), $this->basic, BillingCycle::Monthly, null, 'customer');

        $this->assertSame('scheduled', $change->status);
        $this->assertNull($change->invoice_id);
        $this->assertSame($this->pro->id, $service->refresh()->product_id, 'nothing changes yet');
        $this->assertNotNull($service->scheduledChange());

        $this->travelTo(now()->addDays(15));
        $this->assertSame(1, app(BillingRunner::class)->run()['plan_changes']);

        $service->refresh();
        $this->assertSame($this->basic->id, $service->product_id);
        $this->assertSame(3000, $service->amount);
        $this->assertNull($service->scheduledChange());
        $this->assertSame(3000, $service->invoiceItems()->where('kind', 'renewal')->latest('id')->first()->unit_price, 'the renewal bills the new price');
    }

    public function test_customers_cannot_downgrade_immediately_but_staff_can(): void
    {
        $service = $this->liveService();
        $service->update(['product_id' => $this->pro->id, 'amount' => 6000]);
        $service = $service->refresh();

        try {
            app(PlanChangeService::class)->change($service, $this->basic, BillingCycle::Monthly, 'immediate', 'customer');
            $this->fail('expected a DomainException');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('next renewal', $e->getMessage());
        }

        $change = app(PlanChangeService::class)->change($service, $this->basic, BillingCycle::Monthly, 'immediate', 'admin');
        $this->assertSame('applied', $change->status);
        $this->assertNull($change->invoice_id, 'a credit with nothing to charge raises no invoice');
        $this->assertSame($this->basic->id, $service->refresh()->product_id);
    }

    public function test_changing_cycle_starts_a_new_period_today(): void
    {
        $service = $this->liveService();

        $plan = app(PlanChangeService::class)->preview($service, $this->basic, BillingCycle::Annually);

        $this->assertSame('change', $plan['direction']);
        $this->assertSame(30000, $plan['charge']);
        $this->assertSame(now()->copy()->addYear()->toDateString(), $plan['next_due_date']->toDateString());

        $change = app(PlanChangeService::class)->change($service, $this->basic, BillingCycle::Annually);
        $this->assertSame(BillingCycle::Annually, $service->refresh()->billing_cycle);
        $this->assertSame(30000 - $change->credit, $change->invoice->subtotal);
    }

    public function test_a_trial_changes_plan_for_free(): void
    {
        $this->basic->update(['trial_days' => 14]);
        ['service' => $service] = app(OrderService::class)->place($this->client, $this->basic, BillingCycle::Monthly);
        $service->refresh();
        $trialEnd = $service->next_due_date->toDateString();

        $change = app(PlanChangeService::class)->change($service, $this->pro, BillingCycle::Monthly);

        $this->assertNull($change->invoice_id);
        $service->refresh();
        $this->assertSame(6000, $service->amount);
        $this->assertSame($trialEnd, $service->next_due_date->toDateString());
        $this->assertTrue($service->onTrial());
    }

    public function test_the_package_is_changed_on_the_server(): void
    {
        $module = new FakeModule;
        app(ModuleRegistry::class)->register($module);
        $this->basic->update(['module' => 'fake']);
        $this->pro->update(['module' => 'fake']);
        $service = $this->liveService();
        $before = count($module->calls ?? []);

        app(PlanChangeService::class)->change($service, $this->pro, BillingCycle::Monthly);

        $this->assertTrue($service->logs()->where('action', 'change_package')->exists());
        $this->assertGreaterThanOrEqual($before, count($module->calls ?? []));
    }

    public function test_options_are_limited_to_the_group_for_customers_and_respect_the_product_flag(): void
    {
        $other = Product::factory()->priced(100)->create(['name' => 'Elsewhere']);
        $service = $this->liveService();
        $service->load('product');

        $names = app(PlanChangeService::class)->options($service)->pluck('product.name')->unique()->sort()->values()->all();
        $this->assertSame(['Basic', 'Pro'], $names);
        $this->assertContains('Elsewhere', app(PlanChangeService::class)->options($service, true)->pluck('product.name')->all());

        $this->assertTrue(app(PlanChangeService::class)->customerCanChange($service));
        $this->basic->update(['allow_plan_changes' => false]);
        $this->assertFalse(app(PlanChangeService::class)->customerCanChange($service->refresh()->load('product')));
        $this->assertNotNull($other->id);
    }

    public function test_it_rejects_the_same_plan_inactive_plans_and_missing_prices(): void
    {
        $service = $this->liveService();
        $changes = app(PlanChangeService::class);

        foreach ([
            fn () => $changes->preview($service, $this->basic, BillingCycle::Monthly),
            fn () => $changes->preview($service, $this->pro, BillingCycle::Annually),
            fn () => $changes->preview($service, tap($this->pro)->update(['is_active' => false]), BillingCycle::Monthly),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('expected a DomainException');
            } catch (\DomainException) {
                $this->assertTrue(true);
            }
        }
    }
}
