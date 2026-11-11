<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\AddonService;
use App\Services\BillingRunner;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PlanChangeService;
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
        $invoice = Invoice::firstOrFail();
        $this->assertEqualsCanonicalizing([$parent->id, $service->id], $invoice->items->pluck('service_id')->all());
    }

    public function test_renewal_puts_plan_and_addon_on_one_invoice(): void
    {
        $client = Client::factory()->create();
        $parent = $this->parent($client, daysLeft: 3);
        $result = app(AddonService::class)->add($parent, $this->addon());
        app(PaymentService::class)->record($result['invoice'], 'test', $result['invoice']->total, 'x');

        $before = Invoice::count();
        app(BillingRunner::class)->generateRenewalInvoices();
        $this->assertSame($before + 1, Invoice::count());
        $latest = Invoice::latest('id')->first();
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
        app(PlanChangeService::class)->preview($parent->refresh(), $plan, BillingCycle::Annually, null, true);
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

    public function test_an_addon_ordered_with_the_plan_is_on_the_same_invoice_and_starts_after_the_plan(): void
    {
        $client = Client::factory()->create();
        $plan = Product::factory()->priced(3000)->create(['name' => 'Plan']);
        $addon = $this->addon(1000);

        ['service' => $service, 'invoice' => $invoice] = app(OrderService::class)->place($client, $plan, BillingCycle::Monthly, ['trial' => false, 'addons' => [$addon->slug]]);

        $this->assertCount(2, $invoice->items);
        $this->assertSame(4000, $invoice->subtotal);
        $child = $service->addons()->first();
        $this->assertSame(ServiceStatus::Pending, $child->status);
        $this->assertSame(1000, $child->amount);

        app(PaymentService::class)->record($invoice, 'test', $invoice->total, 'paid');
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertSame(ServiceStatus::Active, $child->refresh()->status);
        $this->assertSame($service->next_due_date->toDateString(), $child->next_due_date->toDateString());
        $this->assertTrue($service->grants('whitelabel'));
    }

    public function test_addons_ordered_with_a_trial_are_free_until_it_ends(): void
    {
        $client = Client::factory()->create();
        $plan = Product::factory()->priced(3000)->create(['name' => 'Plan', 'trial_days' => 14, 'trial_requires_payment_method' => false]);
        $addon = $this->addon(1000);

        ['service' => $service, 'invoice' => $invoice] = app(OrderService::class)->place($client, $plan, BillingCycle::Monthly, ['addons' => [(string) $addon->id]]);

        $this->assertNull($invoice);
        $child = $service->addons()->first();
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertSame(ServiceStatus::Active, $child->refresh()->status);
        $this->assertSame($service->trial_ends_at->toDateString(), $child->trial_ends_at->toDateString());
        $this->assertSame($service->next_due_date->toDateString(), $child->next_due_date->toDateString());
    }

    public function test_an_addon_that_does_not_fit_the_plan_or_cycle_cannot_be_ordered(): void
    {
        $client = Client::factory()->create();
        $plan = Product::factory()->priced(3000)->create(['name' => 'Plan']);
        $other = Product::factory()->priced(3000)->create(['name' => 'Other']);
        $only = $this->addon(1000, ['addon_for' => [$other->id]]);

        try {
            app(OrderService::class)->place($client, $plan, BillingCycle::Monthly, ['addons' => [$only->slug]]);
            $this->fail('accepted an add-on for another plan');
        } catch (\DomainException) {
            $this->assertSame(0, Service::count());
        }
        $any = $this->addon(1000, ['name' => 'Any']);
        $this->expectException(\DomainException::class);
        app(OrderService::class)->place($client, $plan, BillingCycle::Annually, ['addons' => [$any->slug]]);
    }

    public function test_the_plan_page_offers_the_addons_and_the_review_step_adds_them_to_the_total(): void
    {
        $plan = Product::factory()->priced(3000)->create(['name' => 'Plan', 'slug' => 'plan']);
        $addon = $this->addon(1000, ['slug' => 'white-label', 'description' => 'Your own branding']);
        $this->addon(500, ['name' => 'Hidden extra', 'slug' => 'hidden-extra', 'is_visible' => false]);
        $user = $this->customer();

        $this->get(route('store.show', $plan))->assertOk()->assertSee('White label')->assertSee('Your own branding')->assertDontSee('Hidden extra');

        $this->actingAs($user)->get(route('portal.order.create', ['product' => 'plan', 'cycle' => 'monthly', 'addons' => ['white-label']]))
            ->assertOk()->assertSee('White label (add-on)')->assertSee('$40.00');

        $this->actingAs($user)->get(route('portal.order.create', ['product' => 'plan', 'cycle' => 'monthly', 'addons' => ['hidden-extra']]))
            ->assertRedirect(route('store.show', ['product' => 'plan', 'cycle' => 'monthly']));
    }

    public function test_placing_the_order_from_the_form_creates_the_addon_service(): void
    {
        $plan = Product::factory()->priced(3000)->create(['name' => 'Plan', 'slug' => 'plan']);
        $this->addon(1000, ['slug' => 'white-label']);
        $user = $this->customer();
        $client = $user->client;

        $this->actingAs($user)->post(route('portal.order.store'), ['product' => 'plan', 'cycle' => 'monthly', 'addons' => ['white-label']])->assertRedirect();

        $this->assertSame(1, Service::where('client_id', $client->id)->whereNotNull('parent_service_id')->count());
        $this->assertSame(4000, Invoice::where('client_id', $client->id)->first()->subtotal);
    }

    public function test_the_admin_account_details_card_shows_nested_module_data_as_lines_not_one_json_string(): void
    {
        $parent = $this->parent(Client::factory()->create());
        $parent->remember(['custom_domain' => ['host' => 'billing.example.com', 'state' => 'active', 'applied' => true, 'error' => null, 'dcv' => 'billing.example.com.abc.dcv.example.net']])->save();

        $html = $this->actingAs(User::factory()->admin()->create())->get(route('admin.services.show', $parent))->assertOk()->getContent();

        $this->assertStringContainsString('billing.example.com', $html);
        $this->assertStringContainsString('class="kv-sub"', $html);
        $this->assertStringNotContainsString('{&quot;host&quot;', $html);
        $this->assertStringNotContainsString('"host":', $html);
    }

    public function test_an_addon_service_cannot_be_plan_changed_by_customers_or_staff(): void
    {
        $parent = $this->parent(Client::factory()->create());
        $addon = $this->addon(1000, ['allow_plan_changes' => true]);
        $child = app(AddonService::class)->add($parent, $addon)['service'];
        app(PaymentService::class)->record($child->invoiceItems()->first()->invoice, 'test', $child->invoiceItems()->first()->invoice->total, 'x');
        $child->refresh();

        $this->assertSame(ServiceStatus::Active, $child->status);
        $this->assertFalse(app(PlanChangeService::class)->changeable($child));
        $this->assertFalse(app(PlanChangeService::class)->customerCanChange($child));
    }
}
