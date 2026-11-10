<?php

namespace Tests\Feature\Admin;

use App\Enums\BillingCycle;
use App\Models\Client;
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

    private function liveService(Product $product, Client $client): Service
    {
        ['service' => $service, 'invoice' => $invoice] = app(OrderService::class)->place($client, $product, BillingCycle::Monthly, ['trial' => false]);
        app(PaymentService::class)->record($invoice, 'test', $invoice->total, 'first');
        $service->refresh()->update(['next_due_date' => now()->addDays(15)->startOfDay()]);

        return $service->refresh();
    }

    public function test_product_form_saves_trial_and_plan_change_fields(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.products.store'), ['name' => 'Trialled', 'trial_days' => 14, 'trial_requires_payment_method' => '1', 'allow_plan_changes' => '0'])->assertSessionHasNoErrors();
        $p = Product::where('name', 'Trialled')->firstOrFail();
        $this->assertSame(14, $p->trial_days);
        $this->assertTrue($p->trial_requires_payment_method);
        $this->assertFalse($p->allow_plan_changes);

        $this->get(route('admin.products.index'))->assertSee('14-day trial');
        $this->get(route('admin.products.edit', $p))->assertOk()->assertSee('Free trial (days)');
        $this->put(route('admin.products.update', $p), ['name' => 'Trialled', 'trial_days' => -1])->assertSessionHasErrors('trial_days');
    }

    public function test_manual_service_for_a_trial_product_starts_as_a_trial_unless_skipped(): void
    {
        $client = Client::factory()->create();
        $product = Product::factory()->priced(2500)->create(['trial_days' => 10]);
        $this->actingAs($this->admin())->post(route('admin.services.store'), ['client_id' => $client->id, 'product_id' => $product->id, 'billing_cycle' => 'monthly'])->assertRedirect();
        $s = Service::firstOrFail();
        $this->assertTrue($s->onTrial());
        $this->assertSame(now()->addDays(10)->toDateString(), $s->trial_ends_at->toDateString());
        $this->assertDatabaseCount('invoices', 0);

        $other = Client::factory()->create();
        $this->post(route('admin.services.store'), ['client_id' => $other->id, 'product_id' => $product->id, 'billing_cycle' => 'monthly', 'skip_trial' => '1']);
        $this->assertNull(Service::where('client_id', $other->id)->firstOrFail()->trial_ends_at);
    }

    public function test_admin_can_extend_and_end_a_trial(): void
    {
        $client = Client::factory()->create();
        $product = Product::factory()->priced(2500)->create(['trial_days' => 7]);
        $service = app(OrderService::class)->place($client, $product, BillingCycle::Monthly)['service'];
        $service->update(['trial_reminded_at' => now()]);
        $ends = $service->trial_ends_at->copy();

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.services.show', $service))->assertOk()->assertSee('End trial and invoice now');
        $this->post(route('admin.services.trial.extend', $service), ['days' => 5])->assertSessionHas('status');
        $service->refresh();
        $this->assertSame($ends->copy()->addDays(5)->toDateString(), $service->trial_ends_at->toDateString());
        $this->assertSame($service->trial_ends_at->toDateString(), $service->next_due_date->toDateString());
        $this->assertNull($service->trial_reminded_at);

        $this->post(route('admin.services.trial.end', $service))->assertSessionHas('status');
        $this->assertFalse($service->refresh()->onTrial());
        $this->assertSame(1, $client->invoices()->count());
    }

    public function test_admin_previews_then_confirms_a_plan_change(): void
    {
        $group = ProductGroup::create(['name' => 'Hosting', 'slug' => 'hosting']);
        $basic = Product::factory()->priced(3000)->create(['product_group_id' => $group->id, 'name' => 'Basic']);
        $pro = Product::factory()->priced(6000)->create(['product_group_id' => $group->id, 'name' => 'Pro']);
        $client = Client::factory()->create();
        $service = $this->liveService($basic, $client);
        $admin = $this->admin();
        $plan = $pro->id.':monthly';

        $this->actingAs($admin)->get(route('admin.services.show', $service))->assertOk()->assertSee('Change plan')->assertSee('Pro');
        $this->post(route('admin.services.plan-change.preview', $service), ['plan' => $plan])->assertOk()->assertSee('Credit for unused time')->assertSee('Confirm change');
        $this->assertSame($basic->id, $service->refresh()->product_id);

        $this->post(route('admin.services.plan-change.store', $service), ['plan' => $plan, 'timing' => 'immediate'])->assertSessionHas('status');
        $this->assertSame($pro->id, $service->refresh()->product_id);
        $change = $service->planChanges()->firstOrFail();
        $this->assertSame('admin', $change->source);
        $this->assertNotNull($change->invoice_id);
        $this->get(route('admin.services.show', $service))->assertSee('Plan changes');
    }

    public function test_admin_can_schedule_and_cancel_a_change(): void
    {
        $group = ProductGroup::create(['name' => 'Hosting', 'slug' => 'hosting']);
        $basic = Product::factory()->priced(3000)->create(['product_group_id' => $group->id]);
        $pro = Product::factory()->priced(6000)->create(['product_group_id' => $group->id, 'name' => 'Pro']);
        $service = $this->liveService($basic, Client::factory()->create());

        $this->actingAs($this->admin())->post(route('admin.services.plan-change.store', $service), ['plan' => $pro->id.':monthly', 'timing' => 'renewal']);
        $this->assertNotNull($service->scheduledChange());
        $this->get(route('admin.services.show', $service))->assertSee('Cancel scheduled change');
        $this->delete(route('admin.services.plan-change.cancel', $service))->assertSessionHas('status');
        $this->assertNull($service->scheduledChange());
    }

    public function test_billing_settings_include_the_new_fields(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.settings.index', 'billing'))->assertOk()->assertSee('Let customers change plan themselves');
        $this->put(route('admin.settings.update', 'billing'), ['s' => ['billing__customer_plan_changes' => '0', 'billing__trial_reminder_days' => '5', 'billing__trial_invoice_days_before' => '2']])->assertSessionHasNoErrors();
        $this->assertFalse(Settings::bool('billing.customer_plan_changes', true));
        $this->assertSame('5', (string) Settings::get('billing.trial_reminder_days'));
    }
}
