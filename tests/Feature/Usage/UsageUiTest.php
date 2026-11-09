<?php

namespace Tests\Feature\Usage;

use App\Enums\BillingCycle;
use App\Models\Product;
use App\Models\UsageMeter;
use App\Models\UsageRecord;
use App\Services\OrderService;
use App\Services\UsageService;

class UsageUiTest extends UsageTestCase
{
    public function test_admin_product_form_saves_meters_and_keeps_them_when_section_is_absent(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.products.store'), [
            'name' => 'Metered thing', 'is_active' => 1, 'meters_present' => 1,
            'prices' => [['cycle' => 'monthly', 'currency' => 'USD', 'amount' => '5']],
            'meters' => [
                ['key' => 'api_calls', 'name' => 'API calls', 'unit' => 'calls', 'aggregation' => 'sum', 'included_quantity' => '1000', 'pricing_model' => 'tiered',
                    'prices' => ['USD' => ['unit_price' => '', 'tiers' => "5000 | 0.002\n | 0.001 | 0.01"]]],
                ['key' => 'gb', 'name' => 'Bandwidth', 'unit' => 'GB', 'aggregation' => 'max', 'pricing_model' => 'per_unit', 'prices' => ['USD' => ['unit_price' => '0.08']]],
            ],
        ])->assertRedirect();

        $product = Product::firstWhere('name', 'Metered thing');
        $api = $product->meters()->with('prices')->firstWhere('key', 'api_calls');
        $this->assertSame('tiered', $api->pricing_model);
        $tiers = $api->prices->first()->tiers;
        $this->assertSame(200000, $tiers[0]['unit_price']);
        $this->assertNull($tiers[1]['up_to']);
        $this->assertSame(1000000, $tiers[1]['flat_fee']);
        $this->assertSame(8000000, $product->meters()->firstWhere('key', 'gb')->prices->first()->unit_price);

        $this->actingAs($admin)->get(route('admin.products.edit', $product))->assertOk()->assertSee('api_calls')->assertSee('0.002');

        // an update without the metered section must not wipe the meters
        $this->actingAs($admin)->put(route('admin.products.update', $product), ['name' => 'Renamed', 'prices' => [['cycle' => 'monthly', 'currency' => 'USD', 'amount' => '5']]])->assertSessionHasNoErrors();
        $this->assertSame(2, $product->meters()->count());

        // removing a meter via the form removes it
        $this->actingAs($admin)->put(route('admin.products.update', $product), ['name' => 'Renamed', 'meters_present' => 1, 'prices' => [['cycle' => 'monthly', 'currency' => 'USD', 'amount' => '5']],
            'meters' => [['key' => 'gb', 'name' => 'Bandwidth', 'aggregation' => 'max', 'pricing_model' => 'per_unit', 'prices' => ['USD' => ['unit_price' => '0.08']]]]])->assertSessionHasNoErrors();
        $this->assertSame(['gb'], $product->meters()->pluck('key')->all());
    }

    public function test_invalid_meter_input_is_rejected(): void
    {
        $this->actingAs($this->admin())->post(route('admin.products.store'), [
            'name' => 'Bad', 'meters_present' => 1, 'prices' => [],
            'meters' => [['key' => 'Bad Key', 'name' => 'x', 'aggregation' => 'sum', 'pricing_model' => 'package', 'prices' => []]],
        ])->assertSessionHasErrors(['meters.0.key']);
        $this->assertSame(0, UsageMeter::count());
    }

    public function test_admin_can_record_usage_from_the_service_page(): void
    {
        [$service, $meter] = $this->meteredService();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.services.usage.store', $service), ['meter' => 'api_calls', 'quantity' => '2500', 'idempotency_key' => 'k1'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.services.usage.store', $service), ['meter' => 'api_calls', 'quantity' => '2500', 'idempotency_key' => 'k1']);
        $this->assertSame(1, UsageRecord::count());
        $this->actingAs($admin)->post(route('admin.services.usage.store', $service), ['meter' => 'ghost', 'quantity' => '1'])->assertSessionHasErrors('usage');

        $this->actingAs($admin)->get(route('admin.services.show', $service))->assertOk()
            ->assertSee('Metered usage')->assertSee('API calls')->assertSee('2,500 calls')->assertSee('$5.00');

        $record = UsageRecord::first();
        $this->actingAs($admin)->delete(route('admin.services.usage.destroy', [$service, $record->id]))->assertSessionHasNoErrors();
        $this->assertSame(0, UsageRecord::count());
    }

    public function test_customers_cannot_post_usage(): void
    {
        [$service] = $this->meteredService();
        $this->actingAs($this->customer())->post(route('admin.services.usage.store', $service), ['meter' => 'api_calls', 'quantity' => '1'])->assertForbidden();
        $this->assertSame(0, UsageRecord::count());
    }

    public function test_portal_shows_usage_to_the_owner_only(): void
    {
        [$service] = $this->meteredService();
        $owner = $service->client->users()->first() ?? $this->customer();
        $owner->client()->associate($service->client)->save();
        app(UsageService::class)->record($service, 'api_calls', 2500, null, 'a');

        $this->actingAs($owner)->get(route('portal.services.show', $service))->assertOk()
            ->assertSee('Usage this period')->assertSee('2,500 calls')->assertSee('$5.00')->assertDontSee('Record usage');

        $this->actingAs($this->customer())->get(route('portal.services.show', $service))->assertNotFound();
    }

    public function test_portal_hides_the_panel_for_unmetered_products(): void
    {
        $product = Product::factory()->priced(500)->create();
        $user = $this->customer();
        ['service' => $service] = app(OrderService::class)->place($user->client, $product, BillingCycle::Monthly);
        $this->actingAs($user)->get(route('portal.services.show', $service))->assertOk()->assertDontSee('Usage this period');
    }
}
