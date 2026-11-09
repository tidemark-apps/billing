<?php

namespace Tests\Feature\Api;

use App\Enums\ServiceStatus;
use App\Events\UsageRecorded;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Service;
use App\Models\UsageMeter;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\UsageService;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Usage\UsageTestCase;

/** Usage billing over the API: product meters, recording and reading usage (staff), and the read-only summary (customers). */
class UsageTest extends UsageTestCase
{
    use ApiTestHelpers;

    private function ownerOf(Service $service): User
    {
        return User::factory()->create(['client_id' => $service->client_id]);
    }

    private function record(Service $service, array $body = [], ?User $as = null)
    {
        return $this->as($as ?? $this->staffUser())->postJson("/api/v1/admin/services/{$service->id}/usage", $body + ['meter' => 'api_calls', 'quantity' => 100]);
    }

    // ---- meters -----------------------------------------------------------------------------------------------------

    public function test_staff_create_read_update_and_delete_meters(): void
    {
        $staff = $this->staffUser();
        $product = Product::factory()->priced(1000)->create();
        $base = "/api/v1/admin/products/{$product->id}/meters";

        $this->as($staff)->getJson($base)->assertOk()->assertJsonCount(0, 'data');
        $res = $this->as($staff)->postJson($base, [
            'key' => 'bandwidth_gb', 'name' => 'Bandwidth', 'unit' => 'GB', 'aggregation' => 'sum', 'pricing_model' => 'per_unit', 'included_quantity' => 100,
            'prices' => [['currency' => 'usd', 'unit_price' => 200000], ['currency' => 'EUR', 'unit_price' => 180000]],
        ])->assertCreated();
        $res->assertJsonPath('data.key', 'bandwidth_gb')->assertJsonPath('data.included_quantity', '100')->assertJsonPath('data.package_size', null)
            ->assertJsonPath('data.prices.0.currency', 'EUR')->assertJsonPath('data.prices.1.currency', 'USD')->assertJsonPath('data.prices.1.unit_price', 200000)
            ->assertJsonPath('data.prices.1.unit_price_display', '0.002')->assertJsonPath('data.product_id', $product->id);
        $id = $res->json('data.id');
        $this->assertDatabaseHas('usage_meters', ['product_id' => $product->id, 'key' => 'bandwidth_gb', 'unit' => 'GB']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'product.meter_created']);

        // a second, tiered meter keeps the first
        $this->as($staff)->postJson($base, [
            'key' => 'storage', 'name' => 'Storage', 'aggregation' => 'max', 'pricing_model' => 'tiered',
            'prices' => [['currency' => 'USD', 'tiers' => [['up_to' => 10, 'unit_price' => 0], ['up_to' => null, 'unit_price' => 3000, 'flat_fee' => 100]]]],
        ])->assertCreated()->assertJsonPath('data.unit', 'unit')->assertJsonPath('data.prices.0.tiers.0.up_to', '10')->assertJsonPath('data.prices.0.tiers.1.up_to', null)->assertJsonPath('data.prices.0.tiers.1.flat_fee', 100);
        $this->as($staff)->getJson($base)->assertJsonCount(2, 'data')->assertJsonPath('data.0.key', 'bandwidth_gb')->assertJsonPath('data.1.key', 'storage');
        $this->as($staff)->getJson("$base/$id")->assertOk()->assertJsonPath('data.name', 'Bandwidth');

        // partial update: name and allowance change, prices stay
        $this->as($staff)->patchJson("$base/$id", ['name' => 'Traffic', 'included_quantity' => '250.5'])->assertOk()->assertJsonPath('data.name', 'Traffic')->assertJsonPath('data.included_quantity', '250.5')->assertJsonCount(2, 'data.prices');
        // sending prices replaces them all
        $this->as($staff)->patchJson("$base/$id", ['prices' => [['currency' => 'USD', 'unit_price' => 10000]]])->assertOk()->assertJsonCount(1, 'data.prices')->assertJsonPath('data.prices.0.unit_price_display', '0.0001');
        $this->assertSame(1, $this->usageMeter($id)->prices()->count());
        $this->assertSame(2, UsageMeter::where('product_id', $product->id)->count());

        $this->as($staff)->deleteJson("$base/$id")->assertNoContent();
        $this->assertDatabaseMissing('usage_meters', ['id' => $id]);
        $this->as($staff)->getJson($base)->assertJsonCount(1, 'data')->assertJsonPath('data.0.key', 'storage');
        $this->as($staff)->getJson("$base/$id")->assertNotFound();
    }

    private function usageMeter(int $id): UsageMeter
    {
        return UsageMeter::findOrFail($id);
    }

    public function test_meter_validation(): void
    {
        $staff = $this->staffUser();
        $product = Product::factory()->priced(1000)->create();
        $base = "/api/v1/admin/products/{$product->id}/meters";
        $ok = ['key' => 'calls', 'name' => 'Calls', 'aggregation' => 'sum', 'pricing_model' => 'per_unit', 'prices' => [['currency' => 'USD', 'unit_price' => 5]]];

        $this->as($staff)->postJson($base, [])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['key', 'name', 'aggregation', 'pricing_model']]]);
        $this->as($staff)->postJson($base, ['key' => 'Bad Key'] + $ok)->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['key']]]);
        $this->as($staff)->postJson($base, ['aggregation' => 'avg'] + $ok)->assertStatus(422);
        $this->as($staff)->postJson($base, ['pricing_model' => 'package'] + $ok)->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['package_size']]]);
        $this->as($staff)->postJson($base, ['prices' => [['currency' => 'ZZZ', 'unit_price' => 1]]] + $ok)->assertStatus(422);
        $this->as($staff)->postJson($base, ['prices' => [['currency' => 'USD']]] + $ok)->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['prices.0.unit_price']]]);
        $this->as($staff)->postJson($base, ['prices' => [['currency' => 'USD', 'unit_price' => 1], ['currency' => 'usd', 'unit_price' => 2]]] + $ok)->assertStatus(422);
        $this->as($staff)->postJson($base, ['pricing_model' => 'tiered', 'prices' => [['currency' => 'USD', 'unit_price' => 1]]] + $ok)->assertStatus(422);
        $this->as($staff)->postJson($base, ['included_quantity' => -1] + $ok)->assertStatus(422);
        $this->assertSame(0, UsageMeter::count());

        $this->as($staff)->postJson($base, $ok)->assertCreated();
        $this->as($staff)->postJson($base, $ok)->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['key']]]); // duplicate key
        $id = UsageMeter::first()->id;
        $this->as($staff)->patchJson("$base/$id", ['key' => 'renamed'])->assertStatus(422); // keys are immutable
        $this->as($staff)->patchJson("$base/$id", ['pricing_model' => 'package'])->assertStatus(422);
        $this->as($staff)->patchJson("$base/$id", ['pricing_model' => 'package', 'package_size' => 1000])->assertOk()->assertJsonPath('data.package_size', '1000');
        $this->as($staff)->patchJson("$base/$id", ['pricing_model' => 'per_unit'])->assertOk()->assertJsonPath('data.package_size', null);
    }

    public function test_a_meter_of_another_product_is_not_found_and_an_invoiced_meter_cannot_be_removed(): void
    {
        [$service, $meter] = $this->meteredService();
        $staff = $this->staffUser();
        $other = Product::factory()->priced(1)->create();
        $base = "/api/v1/admin/products/{$service->product_id}/meters";

        $this->as($staff)->getJson("/api/v1/admin/products/{$other->id}/meters/{$meter->id}")->assertNotFound();
        $this->as($staff)->patchJson("/api/v1/admin/products/{$other->id}/meters/{$meter->id}", ['name' => 'x'])->assertNotFound();
        $this->as($staff)->deleteJson("/api/v1/admin/products/{$other->id}/meters/{$meter->id}")->assertNotFound();
        $this->as($staff)->getJson('/api/v1/admin/products/999999/meters')->assertNotFound();

        // usage that is still unbilled goes with the meter...
        $record = app(UsageService::class)->record($service, 'api_calls', 5);
        $this->as($staff)->deleteJson("$base/{$meter->id}")->assertNoContent();
        $this->assertDatabaseMissing('usage_records', ['id' => $record->id]);

        // ...but once invoiced the meter stays
        [$service, $meter] = $this->meteredService();
        $record = app(UsageService::class)->record($service, 'api_calls', 5);
        $record->update(['invoiced_at' => now()]);
        $this->as($staff)->deleteJson("/api/v1/admin/products/{$service->product_id}/meters/{$meter->id}")->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $this->assertDatabaseHas('usage_meters', ['id' => $meter->id]);
        $this->assertDatabaseHas('usage_records', ['id' => $record->id]);
    }

    public function test_meters_appear_on_admin_and_public_product_resources(): void
    {
        [$service] = $this->meteredService(['included_quantity' => 10]);
        $plain = Product::factory()->priced(500)->create();

        $pub = $this->flushHeaders()->getJson('/api/v1/products/'.$service->product->slug)->assertOk();
        $pub->assertJsonPath('data.meters.0.key', 'api_calls')->assertJsonPath('data.meters.0.included_quantity', '10')->assertJsonPath('data.meters.0.prices.0.unit_price', 200000);
        $list = $this->getJson('/api/v1/products')->assertOk()->json('data');
        $this->assertSame([], collect($list)->firstWhere('id', $plain->id)['meters']);
        $this->assertCount(1, collect($list)->firstWhere('id', $service->product_id)['meters']);

        $staff = $this->staffUser();
        $this->as($staff)->getJson("/api/v1/admin/products/{$service->product_id}")->assertOk()->assertJsonPath('data.meters.0.key', 'api_calls');
        $this->as($staff)->getJson('/api/v1/admin/products')->assertOk()->assertJsonPath('data.0.id', fn ($id) => is_int($id));
    }

    // ---- recording ----------------------------------------------------------------------------------------------------

    public function test_recording_usage_is_idempotent_201_then_200(): void
    {
        [$service] = $this->meteredService();
        Event::fake([UsageRecorded::class]);

        $first = $this->record($service, ['quantity' => '12.5', 'idempotency_key' => 'job-1', 'source' => 'worker', 'recorded_at' => '2026-11-02T10:00:00Z', 'metadata' => ['host' => 'a']])->assertCreated();
        $first->assertJsonPath('data.meter', 'api_calls')->assertJsonPath('data.quantity', '12.5')->assertJsonPath('data.source', 'worker')->assertJsonPath('data.idempotency_key', 'job-1')
            ->assertJsonPath('data.invoiced', false)->assertJsonPath('data.metadata.host', 'a')->assertJsonPath('data.recorded_at', '2026-11-02T10:00:00+00:00');
        $again = $this->record($service, ['quantity' => 999, 'idempotency_key' => 'job-1'])->assertOk();
        $again->assertJsonPath('data.id', $first->json('data.id'))->assertJsonPath('data.quantity', '12.5');

        $this->assertSame(1, UsageRecord::count());
        Event::assertDispatchedTimes(UsageRecorded::class, 1);
        $this->assertSame(1, ActivityLog::where('action', 'usage.recorded')->count());

        // records without a key are never deduplicated; the same key on another meter is a different record
        $this->record($service)->assertCreated();
        $this->record($service)->assertCreated();
        $this->assertSame(3, UsageRecord::count());
        $this->assertSame('api', UsageRecord::latest('id')->first()->source);
    }

    public function test_recording_validation_errors_are_422(): void
    {
        [$service] = $this->meteredService();

        $this->record($service, ['meter' => 'nope'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['meter']]]);
        $this->record($service, ['quantity' => -1])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['quantity']]]);
        $this->record($service, ['quantity' => 'lots'])->assertStatus(422);
        $this->record($service, ['quantity' => 1e20])->assertStatus(422);
        $this->record($service, ['recorded_at' => 'someday'])->assertStatus(422);
        $this->assertSame(422, $this->as($this->staffUser())->postJson("/api/v1/admin/services/{$service->id}/usage", [])->status());

        $service->update(['status' => ServiceStatus::Cancelled]);
        $this->record($service)->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $service->update(['status' => ServiceStatus::Pending]);
        $this->record($service)->assertStatus(422);
        $service->update(['status' => ServiceStatus::Suspended]);
        $this->record($service)->assertCreated();
        $this->assertSame(1, UsageRecord::count());
    }

    public function test_listing_usage_with_summary_and_filters(): void
    {
        [$service, $meter] = $this->meteredService(['included_quantity' => 50]);
        $other = $service->product->meters()->create(['key' => 'storage', 'name' => 'Storage', 'unit' => 'GB', 'aggregation' => 'max', 'included_quantity' => 0, 'pricing_model' => 'per_unit']);
        $other->prices()->create(['currency' => 'USD', 'unit_price' => 1000000]);
        $usage = app(UsageService::class);
        $usage->record($service, 'api_calls', 100, now()->setDate(2026, 11, 1)->setTime(9, 0), 'a', 'api');
        $invoiced = $usage->record($service, 'api_calls', 30, now()->setDate(2026, 11, 3)->setTime(9, 0), 'b', 'pull');
        $usage->record($service, 'storage', 7, now()->setDate(2026, 11, 5)->setTime(9, 0), 'c', 'api');
        $invoiced->update(['invoiced_at' => now()]);
        $staff = $this->staffUser();
        $url = "/api/v1/admin/services/{$service->id}/usage";

        $res = $this->as($staff)->getJson($url)->assertOk()->assertJsonCount(3, 'data')->assertJsonStructure(['data', 'links', 'meta', 'summary' => ['period_start', 'period_end', 'currency', 'estimated_charge', 'meters']]);
        $res->assertJsonPath('data.0.meter', 'storage'); // newest first
        // the invoiced record is excluded from the estimate: api_calls (100 - 50 included) x 0.002 = 0.10, storage 7 x 0.01 = 0.07 USD
        $res->assertJsonPath('summary.estimated_charge.amount', 17)->assertJsonPath('summary.currency', 'USD')->assertJsonCount(2, 'summary.meters');
        $calls = collect($res->json('summary.meters'))->firstWhere('meter', 'api_calls');
        $this->assertSame(['quantity' => '100', 'included' => '50', 'billable' => '50', 'records' => 1, 'priced' => true], collect($calls)->only(['quantity', 'included', 'billable', 'records', 'priced'])->all());
        $this->assertSame(10, $calls['estimated_charge']['amount']);

        $this->as($staff)->getJson("$url?meter=storage")->assertJsonCount(1, 'data')->assertJsonPath('data.0.quantity', '7');
        $this->as($staff)->getJson("$url?meter=api_calls&invoiced=1")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $invoiced->id)->assertJsonPath('data.0.invoiced', true);
        $this->as($staff)->getJson("$url?invoiced=0")->assertJsonCount(2, 'data');
        $this->as($staff)->getJson("$url?source=pull")->assertJsonCount(1, 'data');
        $this->as($staff)->getJson("$url?from=2026-11-02&to=2026-11-04")->assertJsonCount(1, 'data')->assertJsonPath('data.0.idempotency_key', 'b');
        $this->as($staff)->getJson("$url?per_page=1&page=2")->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.current_page', 2);
        $this->as($staff)->getJson("$url?from=nope")->assertStatus(422);
        $this->as($staff)->getJson('/api/v1/admin/services/999999/usage')->assertNotFound();

        $this->as($staff)->getJson("/api/v1/admin/services/{$service->id}")->assertOk()->assertJsonPath('meta.usage.estimated_charge.amount', 17);
        $plain = $this->makeService();
        $this->as($staff)->getJson("/api/v1/admin/services/{$plain->id}")->assertOk()->assertJsonMissingPath('meta.usage');
        $this->as($staff)->getJson("/api/v1/admin/services/{$plain->id}/usage")->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.estimated_charge.amount', 0);
        $this->as($staff, ['services.read'])->getJson("/api/v1/admin/services/{$service->id}")->assertOk()->assertJsonMissingPath('meta.usage'); // needs usage.read
    }

    public function test_deleting_a_record_only_before_it_is_invoiced(): void
    {
        [$service] = $this->meteredService();
        [$otherService] = $this->meteredService();
        $staff = $this->staffUser();
        $usage = app(UsageService::class);
        $open = $usage->record($service, 'api_calls', 1);
        $billed = $usage->record($service, 'api_calls', 2);
        $billed->update(['invoiced_at' => now()]);
        $foreign = $usage->record($otherService, 'api_calls', 3);

        $this->as($staff)->deleteJson("/api/v1/admin/services/{$service->id}/usage/{$billed->id}")->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $this->as($staff)->deleteJson("/api/v1/admin/services/{$service->id}/usage/{$foreign->id}")->assertNotFound(); // belongs to another service
        $this->as($staff)->deleteJson("/api/v1/admin/services/{$service->id}/usage/{$open->id}")->assertNoContent();
        $this->assertDatabaseMissing('usage_records', ['id' => $open->id]);
        $this->assertDatabaseHas('usage_records', ['id' => $billed->id]);
        $this->assertDatabaseHas('usage_records', ['id' => $foreign->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'usage.deleted']);
    }

    // ---- customers --------------------------------------------------------------------------------------------------

    public function test_a_customer_reads_a_usage_summary_of_their_own_service(): void
    {
        [$service] = $this->meteredService(['included_quantity' => 50]);
        $user = $this->ownerOf($service);
        app(UsageService::class)->record($service, 'api_calls', 150, null, 'k', 'secret-source', ['internal' => true]);

        $res = $this->as($user)->getJson("/api/v1/services/{$service->uuid}/usage")->assertOk();
        $res->assertJsonPath('data.estimated_charge.amount', 20)->assertJsonPath('data.meters.0.meter', 'api_calls')->assertJsonPath('data.meters.0.billable', '100')->assertJsonPath('data.meters.0.records', 1);
        $this->assertStringNotContainsString('secret-source', $res->getContent()); // raw records are staff-only
        $this->assertStringNotContainsString('internal', $res->getContent());

        $this->as($user)->getJson("/api/v1/services/{$service->uuid}")->assertOk()->assertJsonPath('meta.usage.estimated_charge.amount', 20);
        $this->as($user, ['services.read'])->getJson("/api/v1/services/{$service->uuid}")->assertOk()->assertJsonMissingPath('meta.usage');
    }

    public function test_a_customer_cannot_read_another_clients_usage(): void
    {
        [$service] = $this->meteredService();
        $intruder = $this->customer();
        app(UsageService::class)->record($service, 'api_calls', 1);

        $this->as($intruder)->getJson("/api/v1/services/{$service->uuid}/usage")->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->as($intruder)->getJson('/api/v1/services/00000000-0000-0000-0000-000000000000/usage')->assertNotFound();
    }

    public function test_usage_routes_need_authentication_and_the_right_ability(): void
    {
        [$service, $meter] = $this->meteredService();
        $owner = $this->ownerOf($service);
        $staff = $this->staffUser();
        $record = app(UsageService::class)->record($service, 'api_calls', 1);
        $meters = "/api/v1/admin/products/{$service->product_id}/meters";

        $this->flushHeaders()->getJson("/api/v1/services/{$service->uuid}/usage")->assertStatus(401);
        $this->as($owner, ['services.read'])->getJson("/api/v1/services/{$service->uuid}/usage")->assertStatus(403);
        $this->as($owner, ['usage.read'])->getJson("/api/v1/services/{$service->uuid}/usage")->assertOk();
        $this->as($staff)->getJson("/api/v1/services/{$service->uuid}/usage")->assertStatus(403);

        $admin = [
            ['getJson', $meters, []], ['getJson', "$meters/{$meter->id}", []], ['getJson', "/api/v1/admin/services/{$service->id}/usage", []],
            ['postJson', $meters, []], ['patchJson', "$meters/{$meter->id}", []], ['deleteJson', "$meters/{$meter->id}", []],
            ['postJson', "/api/v1/admin/services/{$service->id}/usage", ['meter' => 'api_calls', 'quantity' => 1]], ['deleteJson', "/api/v1/admin/services/{$service->id}/usage/{$record->id}", []],
        ];
        foreach ($admin as [$verb, $url, $body]) {
            $this->flushHeaders()->{$verb}($url, $body)->assertStatus(401);
            $this->as($owner)->{$verb}($url, $body)->assertStatus(403);
            $write = in_array($verb, ['postJson', 'patchJson', 'deleteJson'], true);
            // the other half of the pair is not enough, and neither are the generic resource abilities
            $this->as($staff, [$write ? 'usage.read' : 'usage.write', 'products.read', 'products.write', 'services.read', 'services.write'])->{$verb}($url, $body)->assertStatus(403);
        }
        $this->as($staff, ['usage.read'])->getJson("/api/v1/admin/services/{$service->id}/usage")->assertOk();
        $this->as($staff, ['usage.write'])->postJson("/api/v1/admin/services/{$service->id}/usage", ['meter' => 'api_calls', 'quantity' => 1])->assertCreated();
        $this->as($staff, ['usage.write'])->getJson("/api/v1/admin/services/{$service->id}/usage")->assertStatus(403);
    }

    public function test_usage_recorded_is_in_the_event_catalogue(): void
    {
        $names = collect($this->as($this->admin())->getJson('/api/v1/admin/events')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertContains('usage.recorded', $names);
    }
}
