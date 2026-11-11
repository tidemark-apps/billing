<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use App\Services\AddonService;
use App\Services\BillingRunner;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddonsTest extends TestCase
{
    use RefreshDatabase;

    private function parent(Client $client, ?Product $plan = null, int $daysLeft = 15): Service
    {
        $plan ??= Product::factory()->priced(3000)->create(['name' => 'Plan']);
        ['service' => $service, 'invoice' => $invoice] = app(OrderService::class)->place($client, $plan, BillingCycle::Monthly, ['trial' => false]);
        app(PaymentService::class)->record($invoice, 'test', $invoice->total, 'first');
        $service->refresh()->update(['next_due_date' => now()->addDays($daysLeft)->startOfDay()]);

        return $service->refresh();
    }

    private function addon(int $monthly = 1000, array $attrs = []): Product
    {
        $addon = Product::factory()->priced($monthly)->create(['name' => 'White label', 'kind' => 'addon', 'grants' => ['whitelabel']] + $attrs);

        return $addon;
    }

    public function test_addon_products_are_not_orderable_or_listed(): void
    {
        $addon = $this->addon();
        $this->get(route('store.index'))->assertDontSee('White label');
        $this->get(route('store.show', $addon))->assertNotFound();
        $this->expectException(\DomainException::class);
        app(OrderService::class)->place(Client::factory()->create(), $addon, BillingCycle::Monthly);
    }

    public function test_adding_an_addon_charges_the_rest_of_the_period_and_provisions_on_payment(): void
    {
        $client = Client::factory()->create();
        $parent = $this->parent($client, daysLeft: 15);
        $addon = $this->addon(1000);

        $result = app(AddonService::class)->add($parent, $addon);
        $service = $result['service'];
        $this->assertSame($parent->id, $service->parent_service_id);
        $this->assertSame(ServiceStatus::Pending, $service->status);
        $this->assertSame(1000, $service->amount);
        $this->assertSame($parent->next_due_date->toDateString(), $service->next_due_date->toDateString());
        $this->assertSame($parent->billing_cycle, $service->billing_cycle);
        // 15 of the 30-ish days of the period remain
        $this->assertNotNull($result['invoice']);
        $items = $result['invoice']->items;
        $this->assertCount(1, $items);
        $this->assertEqualsWithDelta(500, $items->first()->unit_price, 40);

        app(PaymentService::class)->record($result['invoice'], 'test', $result['invoice']->total, 'x');
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertTrue($parent->refresh()->grants('whitelabel'));
    }

    public function test_one_of_each_addon_and_only_for_its_plans(): void
    {
        $client = Client::factory()->create();
        $other = Product::factory()->priced(500)->create(['name' => 'Other plan']);
        $parent = $this->parent($client);
        $addon = $this->addon(1000, ['addon_for' => [$other->id]]);
        $svc = app(AddonService::class);

        $this->assertTrue($svc->available($parent)->isEmpty());
        $this->expectExceptionMessage('not available for this plan');
        $svc->add($parent, $addon);
    }

    public function test_cannot_add_the_same_addon_twice(): void
    {
        $parent = $this->parent(Client::factory()->create());
        $addon = $this->addon();
        $svc = app(AddonService::class);
        $svc->add($parent, $addon);
        $this->assertTrue($svc->available($parent)->isEmpty());
        $this->expectExceptionMessage('already has that add-on');
        $svc->add($parent, $addon);
    }

    public function test_addon_on_a_trial_is_free_until_the_trial_ends_then_invoiced_with_the_plan(): void
    {
        $client = Client::factory()->create();
        $plan = Product::factory()->priced(3000)->create(['trial_days' => 14]);
        $parent = app(OrderService::class)->place($client, $plan, BillingCycle::Monthly)['service'];
        $this->assertTrue($parent->onTrial());
        $addon = $this->addon(1000);

        $result = app(AddonService::class)->add($parent, $addon);
        $this->assertNull($result['invoice']);
        $service = $result['service']->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame($parent->trial_ends_at->toDateString(), $service->trial_ends_at->toDateString());
        $this->assertTrue($parent->refresh()->grants('whitelabel'));

        $this->travelTo($parent->trial_ends_at->copy()->addHour());
        app(BillingRunner::class)->generateRenewalInvoices();
        $this->assertDatabaseCount('invoices', 1);
        $invoice = \App\Models\Invoice::firstOrFail();
        $this->assertEqualsCanonicalizing([$parent->id, $service->id], $invoice->items->pluck('service_id')->all());
    }

    public function test_renewal_puts_plan_and_addon_on_one_invoice(): void
    {
        $client = Client::factory()->create();
        $parent = $this->parent($client, daysLeft: 3);
        $result = app(AddonService::class)->add($parent, $this->addon());
        app(PaymentService::class)->record($result['invoice'], 'test', $result['invoice']->total, 'x');

        $before = \App\Models\Invoice::count();
        app(BillingRunner::class)->generateRenewalInvoices();
        $this->assertSame($before + 1, \App\Models\Invoice::count());
        $latest = \App\Models\Invoice::latest('id')->first();
        $this->assertCount(2, $latest->items);
    }

    public function test_addon_follows_the_parent_when_suspended_and_cancelled(): void
    {
        $parent = $this->parent(Client::factory()->create());
        $result = app(AddonService::class)->add($parent, $this->addon());
        app(PaymentService::class)->record($result['invoice'], 'test', $result['invoice']->total, 'x');
        $addon = $result['service']->refresh();
        $prov = app(ProvisioningService::class);

        $prov->suspend($parent->refresh(), 'test');
        $this->assertSame(ServiceStatus::Suspended, $addon->refresh()->status);
        $prov->unsuspend($parent->refresh());
        $this->assertSame(ServiceStatus::Active, $addon->refresh()->status);

        $prov->cancel($parent->refresh(), immediately: true);
        $this->assertSame(ServiceStatus::Cancelled, $addon->refresh()->status);
    }

    public function test_an_addon_suspended_on_its_own_stays_suspended_when_the_parent_is_unsuspended(): void
    {
        $parent = $this->parent(Client::factory()->create());
        $result = app(AddonService::class)->add($parent, $this->addon());
        app(PaymentService::class)->record($result['invoice'], 'test', $result['invoice']->total, 'x');
        $addon = $result['service']->refresh();
        $prov = app(ProvisioningService::class);

        $prov->suspend($addon, 'overdue');
        $prov->suspend($parent->refresh());
        $prov->unsuspend($parent->refresh());
        $this->assertSame(ServiceStatus::Suspended, $addon->refresh()->status);
        $this->assertFalse($parent->refresh()->grants('whitelabel'));
    }

    public function test_cancelling_the_parent_at_period_end_flags_its_addons(): void
    {
        $parent = $this->parent(Client::factory()->create());
        $result = app(AddonService::class)->add($parent, $this->addon());
        app(PaymentService::class)->record($result['invoice'], 'test', $result['invoice']->total, 'x');

        app(ProvisioningService::class)->cancel($parent->refresh());
        $this->assertTrue($result['service']->refresh()->cancel_at_period_end);
    }

    public function test_billing_cycle_cannot_change_while_addons_are_attached(): void
    {
        $client = Client::factory()->create();
        $plan = Product::factory()->priced(3000)->create();
        $plan->prices()->create(['cycle' => 'annually', 'currency' => $plan->prices()->first()->currency, 'amount' => 30000, 'setup_fee' => 0]);
        $parent = $this->parent($client, $plan);
        app(AddonService::class)->add($parent, $this->addon());

        $this->expectExceptionMessage('Remove the add-ons');
        app(\App\Services\PlanChangeService::class)->preview($parent->refresh(), $plan, BillingCycle::Annually, null, true);
    }

    public function test_admin_product_form_saves_kind_targets_and_grants(): void
    {
        $plan = Product::factory()->priced(3000)->create();
        $this->actingAs($this->admin())->post(route('admin.products.store'), ['name' => 'WL', 'kind' => 'addon', 'addon_for' => [$plan->id], 'grants' => ['whitelabel']])->assertSessionHasNoErrors();
        $p = Product::where('name', 'WL')->firstOrFail();
        $this->assertTrue($p->isAddon());
        $this->assertSame([$plan->id], $p->addon_for);
        $this->assertSame(['whitelabel'], $p->grants);
        $this->post(route('admin.products.store'), ['name' => 'Bad', 'kind' => 'addon', 'grants' => ['nope']])->assertSessionHasErrors('grants.0');
    }

    public function test_portal_customer_can_add_an_addon_to_their_service(): void
    {
        $user = $this->customer();
        $parent = $this->parent($user->client);
        $addon = $this->addon();

        $this->actingAs($user)->get(route('portal.services.show', $parent))->assertOk()->assertSee('White label')->assertSee('Add-ons');
        $this->post(route('portal.services.addons.store', $parent), ['product_id' => $addon->id])->assertRedirect();
        $this->assertSame(1, $parent->addons()->count());
        $this->assertDatabaseCount('invoices', 2);
    }

    public function test_admin_can_add_an_addon_from_the_service_page(): void
    {
        $parent = $this->parent(Client::factory()->create());
        $addon = $this->addon();
        $this->actingAs($this->admin())->get(route('admin.services.show', $parent))->assertOk()->assertSee('White label');
        $this->post(route('admin.services.addons.store', $parent), ['product_id' => $addon->id])->assertRedirect();
        $this->assertSame(1, $parent->addons()->count());
    }
}
