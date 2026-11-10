<?php

namespace Tests\Feature\Api;

use App\Enums\BillingCycle;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrialsAndPlanChangesApiTest extends TestCase
{
    use ApiTestHelpers, RefreshDatabase;

    private Product $basic;

    private Product $pro;

    protected function setUp(): void
    {
        parent::setUp();
        $group = ProductGroup::create(['name' => 'Hosting', 'slug' => 'hosting']);
        $this->basic = Product::factory()->priced(3000)->create(['product_group_id' => $group->id, 'name' => 'Basic']);
        $this->pro = Product::factory()->priced(6000)->create(['product_group_id' => $group->id, 'name' => 'Pro']);
    }

    /** A paid active service on Basic for the customer's client. */
    private function liveService($user): Service
    {
        ['service' => $service, 'invoice' => $invoice] = app(OrderService::class)->place($user->client, $this->basic, BillingCycle::Monthly);
        app(PaymentService::class)->record($invoice, 'test', $invoice->total, 'first');
        $service->refresh()->update(['next_due_date' => now()->addDays(15)]);

        return $service->refresh();
    }

    private function trialService($user, int $days = 14): Service
    {
        $product = Product::factory()->priced(2000)->create(['trial_days' => $days]);

        return app(OrderService::class)->place($user->client, $product, BillingCycle::Monthly)['service'];
    }

    // ---- products ----------------------------------------------------------------------------------------------

    public function test_admin_product_trial_fields_are_validated_and_saved(): void
    {
        $admin = $this->admin();
        $id = $this->as($admin)->postJson('/api/v1/admin/products', ['name' => 'Trialware', 'trial_days' => 14, 'trial_requires_payment_method' => true, 'allow_plan_changes' => false, 'prices' => [['cycle' => 'monthly', 'currency' => 'USD', 'amount' => 500]]])
            ->assertCreated()->assertJsonPath('data.trial_days', 14)->assertJsonPath('data.trial_requires_payment_method', true)->assertJsonPath('data.allow_plan_changes', false)->json('data.id');
        $this->as($admin)->patchJson("/api/v1/admin/products/$id", ['trial_days' => 0])->assertOk()->assertJsonPath('data.trial_days', 0)->assertJsonPath('data.allow_plan_changes', false);
        $this->as($admin)->patchJson("/api/v1/admin/products/$id", ['trial_days' => -1])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->as($admin)->patchJson("/api/v1/admin/products/$id", ['trial_days' => 9999])->assertStatus(422);
        $this->as($admin)->patchJson("/api/v1/admin/products/$id", ['allow_plan_changes' => 'maybe'])->assertStatus(422);
        $this->as($admin)->getJson("/api/v1/admin/products/$id")->assertOk()->assertJsonPath('data.trial_days', 0);
    }

    public function test_catalog_shows_trial_days_and_availability_to_the_client(): void
    {
        $product = Product::factory()->priced(2000)->create(['trial_days' => 7]);
        $user = $this->customer();

        $this->getJson("/api/v1/products/{$product->id}")->assertOk()->assertJsonPath('data.trial_days', 7)->assertJsonPath('data.trial_available', null)->assertJsonMissingPath('data.allow_plan_changes');
        $this->as($user)->getJson("/api/v1/products/{$product->id}")->assertJsonPath('data.trial_available', true);
        app(OrderService::class)->place($user->client, $product, BillingCycle::Monthly);
        $this->as($user)->getJson("/api/v1/products/{$product->id}")->assertJsonPath('data.trial_available', false);
        $this->as($user)->getJson('/api/v1/products')->assertOk()->assertJsonPath('data.0.trial_available', false);
        $this->as($this->customer())->getJson("/api/v1/products/{$product->id}")->assertJsonPath('data.trial_available', true);
    }

    // ---- orders ------------------------------------------------------------------------------------------------

    public function test_client_order_with_a_trial_returns_a_null_invoice(): void
    {
        $product = Product::factory()->priced(2000)->create(['trial_days' => 14]);
        $user = $this->customer();

        $this->as($user)->postJson('/api/v1/orders', ['product_id' => $product->id, 'billing_cycle' => 'monthly'])->assertCreated()
            ->assertJsonPath('data.invoice', null)->assertJsonPath('data.pay_url', null)->assertJsonPath('data.service.on_trial', true)
            ->assertJsonPath('data.service.trial_ends_at', now()->addDays(14)->toDateString());
        // second order of the same product is billed
        $this->as($user)->postJson('/api/v1/orders', ['product_id' => $product->id, 'billing_cycle' => 'monthly'])->assertCreated()->assertJsonPath('data.service.on_trial', false)->assertJsonStructure(['data' => ['invoice' => ['id'], 'pay_url']]);
    }

    public function test_order_needing_a_payment_method_is_a_422_with_the_reason(): void
    {
        $product = Product::factory()->priced(2000)->create(['trial_days' => 14, 'trial_requires_payment_method' => true]);
        $user = $this->customer();

        $res = $this->as($user)->postJson('/api/v1/orders', ['product_id' => $product->id, 'billing_cycle' => 'monthly'])->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $this->assertNotEmpty($res->json('error.message'));
        $this->assertSame(0, Service::count());

        $this->as($this->admin())->postJson('/api/v1/admin/services', ['client_id' => $user->client_id, 'product_id' => $product->id, 'billing_cycle' => 'monthly'])->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
    }

    public function test_admin_order_trial_and_bill_now(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->priced(2000)->create(['trial_days' => 14]);
        $client = $this->customer()->client;

        $this->as($admin)->postJson('/api/v1/admin/services', ['client_id' => $client->id, 'product_id' => $product->id, 'billing_cycle' => 'monthly'])->assertCreated()
            ->assertJsonPath('data.invoice', null)->assertJsonPath('data.service.on_trial', true);
        $this->as($admin)->postJson('/api/v1/admin/services', ['client_id' => $this->customer()->client_id, 'product_id' => $product->id, 'billing_cycle' => 'monthly', 'trial' => false])->assertCreated()
            ->assertJsonPath('data.service.on_trial', false)->assertJsonStructure(['data' => ['invoice' => ['id']]]);
        $this->as($admin)->postJson('/api/v1/admin/services', ['client_id' => $client->id, 'product_id' => $product->id, 'billing_cycle' => 'monthly', 'trial' => 'x'])->assertStatus(422);
    }

    // ---- admin trial endpoints ---------------------------------------------------------------------------------

    public function test_admin_extends_and_ends_a_trial(): void
    {
        $admin = $this->admin();
        $service = $this->trialService($this->customer());
        $service->update(['trial_reminded_at' => now()]);
        $end = $service->trial_ends_at->copy();

        $this->as($admin)->postJson("/api/v1/admin/services/{$service->id}/trial/extend", ['days' => 0])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->as($admin)->postJson("/api/v1/admin/services/{$service->id}/trial/extend", [])->assertStatus(422);
        $this->as($admin)->postJson("/api/v1/admin/services/{$service->id}/trial/extend", ['days' => 7])->assertOk()
            ->assertJsonPath('data.trial_ends_at', $end->copy()->addDays(7)->toDateString())->assertJsonPath('data.next_due_date', $end->copy()->addDays(7)->toDateString());
        $this->assertNull($service->refresh()->trial_reminded_at);

        $this->as($admin)->postJson("/api/v1/admin/services/{$service->id}/trial/end")->assertOk()->assertJsonPath('data.on_trial', false);
        $this->assertNotNull($service->refresh()->trial_converted_at);
        $this->assertSame(1, $service->invoiceItems()->count());
        // no longer on a trial
        $this->as($admin)->postJson("/api/v1/admin/services/{$service->id}/trial/end")->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $this->as($admin)->postJson("/api/v1/admin/services/{$service->id}/trial/extend", ['days' => 3])->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
    }

    public function test_extending_a_trial_on_a_normal_service_is_a_422(): void
    {
        $service = $this->makeService();
        $this->as($this->admin())->postJson("/api/v1/admin/services/{$service->id}/trial/extend", ['days' => 3])->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
    }

    // ---- admin plan changes ------------------------------------------------------------------------------------

    public function test_admin_plan_options_preview_change_and_history(): void
    {
        $admin = $this->admin();
        $service = $this->liveService($this->customer());
        $base = "/api/v1/admin/services/{$service->id}";

        $this->as($admin)->getJson("$base/plan-options")->assertOk()->assertJsonPath('data.can_change_plan', true)->assertJsonPath('data.options.0.product_id', $this->pro->id);

        $this->as($admin)->postJson("$base/plan-change/preview", [])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->as($admin)->postJson("$base/plan-change/preview", ['product_id' => $this->pro->id, 'billing_cycle' => 'weekly'])->assertStatus(422);
        $this->as($admin)->postJson("$base/plan-change/preview", ['product_id' => $this->pro->id, 'billing_cycle' => 'monthly', 'timing' => 'never'])->assertStatus(422);
        $this->as($admin)->postJson("$base/plan-change/preview", ['product_id' => $this->basic->id, 'billing_cycle' => 'monthly'])->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $preview = $this->as($admin)->postJson("$base/plan-change/preview", ['product_id' => $this->pro->id, 'billing_cycle' => 'monthly'])->assertOk()
            ->assertJsonPath('data.direction', 'upgrade')->assertJsonPath('data.timing', 'immediate')->assertJsonPath('data.to_amount.amount', 6000);
        $this->assertGreaterThan(0, $preview->json('data.amount_due.amount'));
        $this->assertSame(3000, $service->refresh()->amount, 'preview changes nothing');

        $res = $this->as($admin)->postJson("$base/plan-change", ['product_id' => $this->pro->id, 'billing_cycle' => 'monthly'])->assertOk()
            ->assertJsonPath('data.source', 'api')->assertJsonPath('data.status', 'applied')->assertJsonPath('data.invoice.status', 'open');
        $this->assertSame($preview->json('data.amount_due.amount'), $res->json('data.charge.amount') - $res->json('data.credit.amount'));
        $this->assertSame($this->pro->id, $service->refresh()->product_id);

        $this->as($admin)->getJson("$base/plan-changes")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.to_product.name', 'Pro');
    }

    public function test_admin_schedules_and_cancels_a_plan_change(): void
    {
        $admin = $this->admin();
        $service = $this->liveService($this->customer());
        $base = "/api/v1/admin/services/{$service->id}";

        $this->as($admin)->deleteJson("$base/plan-change")->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $this->as($admin)->postJson("$base/plan-change", ['product_id' => $this->pro->id, 'billing_cycle' => 'monthly', 'timing' => 'renewal'])->assertOk()
            ->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.invoice', null);
        $this->as($admin)->getJson("$base")->assertJsonPath('data.scheduled_change.to_product.id', $this->pro->id)->assertJsonPath('data.scheduled_change.to_amount.amount', 6000)
            ->assertJsonPath('data.scheduled_change.effective_date', $service->next_due_date->toDateString());
        $this->as($admin)->getJson('/api/v1/admin/services')->assertJsonPath('data.0.scheduled_change.to_cycle', 'monthly');
        $this->as($admin)->deleteJson("$base/plan-change")->assertNoContent();
        $this->as($admin)->getJson("$base")->assertJsonPath('data.scheduled_change', null);
        $this->assertSame($this->basic->id, $service->refresh()->product_id);
    }

    public function test_admin_plan_options_are_empty_for_a_service_that_cannot_change(): void
    {
        $service = $this->makeService(null, $this->basic, ['status' => 'suspended']);
        $this->as($this->admin())->getJson("/api/v1/admin/services/{$service->id}/plan-options")->assertOk()->assertJsonPath('data.can_change_plan', false)->assertJsonCount(0, 'data.options');
        $this->as($this->admin())->postJson("/api/v1/admin/services/{$service->id}/plan-change", ['product_id' => $this->pro->id, 'billing_cycle' => 'monthly'])->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
    }

    public function test_the_patch_override_still_changes_price_without_an_invoice(): void
    {
        $service = $this->liveService($this->customer());
        $this->as($this->admin())->patchJson("/api/v1/admin/services/{$service->id}", ['amount' => 999])->assertOk()->assertJsonPath('data.price.amount', 999);
        $this->assertSame(0, $service->planChanges()->count());
    }

    public function test_admin_plan_and_trial_endpoints_enforce_abilities(): void
    {
        $admin = $this->admin();
        $service = $this->liveService($this->customer());
        $base = "/api/v1/admin/services/{$service->id}";
        $body = ['product_id' => $this->pro->id, 'billing_cycle' => 'monthly'];

        $this->as($admin, ['services.read'])->getJson("$base/plan-options")->assertOk();
        $this->as($admin, ['services.read'])->getJson("$base/plan-changes")->assertOk();
        $this->as($admin, ['services.read'])->postJson("$base/plan-change/preview", $body)->assertOk();
        $this->as($admin, ['services.read'])->postJson("$base/plan-change", $body)->assertStatus(403);
        $this->as($admin, ['services.read'])->deleteJson("$base/plan-change")->assertStatus(403);
        $this->as($admin, ['services.read'])->postJson("$base/trial/extend", ['days' => 1])->assertStatus(403);
        $this->as($admin, ['services.read'])->postJson("$base/trial/end")->assertStatus(403);
        $this->as($admin, ['clients.read'])->getJson("$base/plan-options")->assertStatus(403);
        $this->as($admin, ['services.write'])->postJson("$base/plan-change", $body)->assertOk();
        $this->as($this->customer())->getJson("$base/plan-options")->assertStatus(403);
        $this->flushHeaders()->getJson("$base/plan-options")->assertStatus(401);
        $this->as($admin)->getJson('/api/v1/admin/services/999999/plan-options')->assertStatus(404);
    }

    // ---- client plan changes -----------------------------------------------------------------------------------

    public function test_client_plan_options_preview_and_change(): void
    {
        $user = $this->customer();
        $service = $this->liveService($user);
        $base = "/api/v1/services/{$service->uuid}";

        $this->as($user)->getJson($base)->assertOk()->assertJsonPath('data.can_change_plan', true)->assertJsonPath('data.on_trial', false)->assertJsonPath('data.scheduled_change', null);
        $this->as($user)->getJson("$base/plan-options")->assertOk()->assertJsonPath('data.can_change_plan', true)->assertJsonPath('data.options.0.product_name', 'Pro');
        $this->as($user)->postJson("$base/plan-change/preview", ['product_id' => $this->pro->id])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->as($user)->postJson("$base/plan-change/preview", ['product_id' => $this->pro->id, 'billing_cycle' => 'monthly'])->assertOk()->assertJsonPath('data.direction', 'upgrade');

        $this->as($user)->postJson("$base/plan-change", ['product_id' => $this->pro->id, 'billing_cycle' => 'monthly'])->assertOk()
            ->assertJsonPath('data.source', 'customer')->assertJsonPath('data.invoice.status', 'open');
        $this->assertSame($this->pro->id, $service->refresh()->product_id);
    }

    public function test_client_downgrade_is_scheduled_and_can_be_cancelled(): void
    {
        $user = $this->customer();
        $service = $this->liveService($user);
        $service->update(['product_id' => $this->pro->id, 'amount' => 6000]);
        $base = "/api/v1/services/{$service->uuid}";

        $this->as($user)->postJson("$base/plan-change", ['product_id' => $this->basic->id, 'billing_cycle' => 'monthly', 'timing' => 'immediate'])->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $this->as($user)->postJson("$base/plan-change", ['product_id' => $this->basic->id, 'billing_cycle' => 'monthly'])->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->as($user)->getJson($base)->assertJsonPath('data.scheduled_change.to_product.name', 'Basic');
        $this->as($user)->deleteJson("$base/plan-change")->assertNoContent();
        $this->as($user)->deleteJson("$base/plan-change")->assertStatus(422);
        $this->as($user)->getJson($base)->assertJsonPath('data.scheduled_change', null);
    }

    public function test_client_cannot_pick_a_plan_that_is_not_offered(): void
    {
        $user = $this->customer();
        $service = $this->liveService($user);
        $other = Product::factory()->priced(100)->create(['name' => 'Elsewhere']);
        $hidden = Product::factory()->priced(9000)->create(['product_group_id' => $this->basic->product_group_id, 'is_visible' => false]);
        $base = "/api/v1/services/{$service->uuid}";

        foreach ([$other, $hidden] as $p) {
            $this->as($user)->postJson("$base/plan-change", ['product_id' => $p->id, 'billing_cycle' => 'monthly'])->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
            $this->as($user)->postJson("$base/plan-change/preview", ['product_id' => $p->id, 'billing_cycle' => 'monthly'])->assertStatus(422);
        }
        $this->assertSame($this->basic->id, $service->refresh()->product_id);
    }

    public function test_client_plan_changes_can_be_disabled_globally_or_per_product(): void
    {
        $user = $this->customer();
        $service = $this->liveService($user);
        $base = "/api/v1/services/{$service->uuid}";
        $body = ['product_id' => $this->pro->id, 'billing_cycle' => 'monthly'];

        Settings::set('billing.customer_plan_changes', false);
        $this->as($user)->getJson("$base/plan-options")->assertOk()->assertJsonPath('data.can_change_plan', false)->assertJsonCount(0, 'data.options');
        $this->as($user)->getJson($base)->assertJsonPath('data.can_change_plan', false);
        $this->as($user)->postJson("$base/plan-change", $body)->assertStatus(422);

        Settings::set('billing.customer_plan_changes', true);
        $this->basic->update(['allow_plan_changes' => false]);
        $this->as($user)->getJson("$base/plan-options")->assertJsonPath('data.can_change_plan', false);
        $this->as($user)->postJson("$base/plan-change/preview", $body)->assertStatus(422);
        $this->assertSame($this->basic->id, $service->refresh()->product_id);
    }

    public function test_client_cannot_reach_another_clients_service(): void
    {
        $owner = $this->customer();
        $service = $this->liveService($owner);
        $intruder = $this->customer();
        $base = "/api/v1/services/{$service->uuid}";
        $body = ['product_id' => $this->pro->id, 'billing_cycle' => 'monthly'];

        $this->as($intruder)->getJson("$base/plan-options")->assertStatus(404);
        $this->as($intruder)->postJson("$base/plan-change/preview", $body)->assertStatus(404);
        $this->as($intruder)->postJson("$base/plan-change", $body)->assertStatus(404);
        $this->as($intruder)->deleteJson("$base/plan-change")->assertStatus(404);
        $this->assertSame($this->basic->id, $service->refresh()->product_id);
    }

    public function test_client_plan_endpoints_enforce_abilities_and_audience(): void
    {
        $user = $this->customer();
        $service = $this->liveService($user);
        $base = "/api/v1/services/{$service->uuid}";
        $body = ['product_id' => $this->pro->id, 'billing_cycle' => 'monthly'];

        $this->as($user, ['invoices.read'])->getJson("$base/plan-options")->assertStatus(403);
        $this->as($user, ['services.read'])->postJson("$base/plan-change/preview", $body)->assertOk();
        $this->as($user, ['services.read'])->postJson("$base/plan-change", $body)->assertStatus(403);
        $this->as($user, ['services.read'])->deleteJson("$base/plan-change")->assertStatus(403);
        $this->as($this->admin())->getJson("$base/plan-options")->assertStatus(403);
        $this->flushHeaders()->getJson("$base/plan-options")->assertStatus(401);
        $this->assertSame($this->basic->id, $service->refresh()->product_id);
    }

    public function test_client_service_exposes_trial_fields(): void
    {
        $user = $this->customer();
        $service = $this->trialService($user);

        $this->as($user)->getJson("/api/v1/services/{$service->uuid}")->assertOk()->assertJsonPath('data.on_trial', true)
            ->assertJsonPath('data.trial_ends_at', $service->trial_ends_at->toDateString())->assertJsonPath('data.trial_converted_at', null);
        $this->as($user)->getJson('/api/v1/services')->assertJsonPath('data.0.on_trial', true);
    }
}
