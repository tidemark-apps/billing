<?php

namespace Tests\Feature\Portal;

use App\Enums\BillingCycle;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrialsAndPlanChangesTest extends TestCase
{
    use RefreshDatabase;

    private Product $basic;

    private Product $pro;

    protected function setUp(): void
    {
        parent::setUp();
        $group = ProductGroup::create(['name' => 'Hosting', 'slug' => 'hosting']);
        $this->basic = Product::factory()->priced(3000)->create(['product_group_id' => $group->id, 'name' => 'Basic']);
        $this->pro = Product::factory()->priced(6000)->create(['product_group_id' => $group->id, 'name' => 'Pro']);
    }

    private function liveService($user, ?Product $product = null): Service
    {
        ['service' => $service, 'invoice' => $invoice] = app(OrderService::class)->place($user->client, $product ?? $this->basic, BillingCycle::Monthly);
        app(PaymentService::class)->record($invoice, 'test', $invoice->total, 'first');
        $service->refresh()->update(['next_due_date' => now()->addDays(15)->startOfDay()]);

        return $service->refresh();
    }

    public function test_trial_order_starts_without_an_invoice_and_the_service_page_shows_the_banner(): void
    {
        $user = $this->customer();
        $product = Product::factory()->priced(1500)->create(['trial_days' => 14]);

        $this->actingAs($user)->get(route('portal.order.create', ['product' => $product->slug, 'cycle' => 'monthly']))->assertOk()->assertSee('14-day free trial')->assertSee('Start free trial');
        $res = $this->post(route('portal.order.store'), ['product' => $product->slug, 'cycle' => 'monthly']);
        $service = $user->client->services()->firstOrFail();
        $res->assertRedirect(route('portal.services.show', $service))->assertSessionHas('order_placed.trial', true);
        $this->assertSame(0, $user->client->invoices()->count());
        $this->get(route('portal.services.show', $service))->assertOk()->assertSee('Free trial until')->assertSee('cancel any time', false);
        $this->get(route('portal.services.index'))->assertSee('Trial');
    }

    public function test_a_trial_that_needs_a_payment_method_sends_the_customer_to_add_one(): void
    {
        $user = $this->customer();
        $product = Product::factory()->priced(1500)->create(['trial_days' => 7, 'trial_requires_payment_method' => true]);

        $this->actingAs($user)->post(route('portal.order.store'), ['product' => $product->slug, 'cycle' => 'monthly'])
            ->assertRedirect(route('portal.order.create', ['product' => $product->slug, 'cycle' => 'monthly']))->assertSessionHas('needs_payment_method');
        $this->assertSame(0, Service::count());
        $this->get(route('portal.order.create', ['product' => $product->slug, 'cycle' => 'monthly']))->assertSee(route('portal.payment-methods.index'));
    }

    public function test_upgrade_preview_then_confirm_creates_an_invoice(): void
    {
        $user = $this->customer();
        $service = $this->liveService($user);
        $plan = $this->pro->id.':monthly';

        $this->actingAs($user)->get(route('portal.services.show', $service))->assertOk()->assertSee('Change plan');
        $this->post(route('portal.services.plan-change.preview', $service), ['plan' => $plan])->assertOk()->assertSee('Amount due now')->assertSee('Confirm change');
        $this->assertSame($this->basic->id, $service->refresh()->product_id);

        $res = $this->post(route('portal.services.plan-change.store', $service), ['plan' => $plan]);
        $change = $service->planChanges()->firstOrFail();
        $this->assertSame($this->pro->id, $service->refresh()->product_id);
        $this->assertSame('customer', $change->source);
        $res->assertRedirect(route('portal.invoices.show', $change->invoice_id));
    }

    public function test_downgrade_is_scheduled_for_renewal_and_can_be_cancelled(): void
    {
        $user = $this->customer();
        $service = $this->liveService($user, $this->pro);

        $this->actingAs($user)->post(route('portal.services.plan-change.preview', $service), ['plan' => $this->basic->id.':monthly'])->assertOk()->assertSee('Downgrades apply at the next renewal');
        $this->post(route('portal.services.plan-change.store', $service), ['plan' => $this->basic->id.':monthly'])->assertRedirect(route('portal.services.show', $service));
        $this->assertSame($this->pro->id, $service->refresh()->product_id);
        $this->assertNotNull($service->scheduledChange());
        $this->get(route('portal.services.show', $service))->assertSee('Cancel scheduled change');

        $this->delete(route('portal.services.plan-change.cancel', $service))->assertSessionHas('status');
        $this->assertNull($service->scheduledChange());
    }

    public function test_cannot_change_another_clients_service(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        $service = $this->liveService($b);

        $this->actingAs($a)->post(route('portal.services.plan-change.preview', $service), ['plan' => $this->pro->id.':monthly'])->assertNotFound();
        $this->post(route('portal.services.plan-change.store', $service), ['plan' => $this->pro->id.':monthly'])->assertNotFound();
        $this->delete(route('portal.services.plan-change.cancel', $service))->assertNotFound();
    }

    public function test_cannot_change_when_the_product_or_setting_disallows_it(): void
    {
        $user = $this->customer();
        $service = $this->liveService($user);
        $plan = $this->pro->id.':monthly';

        $this->basic->update(['allow_plan_changes' => false]);
        $this->actingAs($user)->get(route('portal.services.show', $service))->assertDontSee('Change plan');
        $this->post(route('portal.services.plan-change.store', $service), ['plan' => $plan])->assertSessionHas('error');
        $this->assertSame($this->basic->id, $service->refresh()->product_id);

        $this->basic->update(['allow_plan_changes' => true]);
        Settings::set('billing.customer_plan_changes', 'false');
        $this->post(route('portal.services.plan-change.store', $service), ['plan' => $plan])->assertSessionHas('error');
        $this->assertSame($this->basic->id, $service->refresh()->product_id);
    }

    public function test_cannot_switch_to_a_plan_outside_the_offered_ones(): void
    {
        $user = $this->customer();
        $service = $this->liveService($user);
        $other = Product::factory()->priced(100)->create(['name' => 'Elsewhere']);

        $this->actingAs($user)->post(route('portal.services.plan-change.store', $service), ['plan' => $other->id.':monthly'])->assertSessionHas('error');
        $this->assertSame($this->basic->id, $service->refresh()->product_id);
    }
}
