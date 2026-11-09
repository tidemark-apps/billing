<?php

namespace Tests\Feature\Usage;

use App\Events\UsageRecorded;
use App\Models\UsageRecord;
use App\Services\UsageService;
use Illuminate\Support\Facades\Event;

class UsageServiceTest extends UsageTestCase
{
    public function test_per_unit_cost_with_included_allowance(): void
    {
        [, $meter] = $this->meteredService(['included_quantity' => 500]);
        $charge = app(UsageService::class)->cost($meter, 1500, 'USD');

        $this->assertSame('1000.000000', $charge->billable);
        $this->assertSame(200, $charge->amount);               // 1000 calls x 0.2 cent
        $this->assertSame('200000000', $charge->micro);
        $this->assertSame(0, app(UsageService::class)->cost($meter, 400, 'USD')->amount);
        $this->assertStringContainsString('500 included', $charge->describe());
    }

    public function test_graduated_tiers_with_flat_fee(): void
    {
        [, $meter] = $this->meteredService(['pricing_model' => 'tiered'], ['unit_price' => 0, 'tiers' => [
            ['up_to' => '1000', 'unit_price' => 100000, 'flat_fee' => 0],
            ['up_to' => '5000', 'unit_price' => 50000, 'flat_fee' => 2000000],
            ['up_to' => null, 'unit_price' => 10000, 'flat_fee' => 0],
        ]]);
        $svc = app(UsageService::class);

        $this->assertSame(310 + 2, $svc->cost($meter, 6000, 'USD')->amount);   // 100c + 200c + 10c + 2c flat
        $this->assertSame(50, $svc->cost($meter, 500, 'USD')->amount);        // stays inside tier 1, no flat fee
        $this->assertSame(0, $svc->cost($meter, 0, 'USD')->amount);
    }

    public function test_package_pricing_charges_every_started_package(): void
    {
        [, $meter] = $this->meteredService(['pricing_model' => 'package', 'package_size' => 1000], ['unit_price' => 5000000]); // 5 cents per 1,000
        $svc = app(UsageService::class);

        $this->assertSame(15, $svc->cost($meter, 2500, 'USD')->amount);
        $this->assertSame(10, $svc->cost($meter, 2000, 'USD')->amount);
        $this->assertSame(5, $svc->cost($meter, '0.000001', 'USD')->amount);
        $this->assertSame(0, $svc->cost($meter, 0, 'USD')->amount);
    }

    public function test_aggregations_sum_max_last(): void
    {
        $svc = app(UsageService::class);
        foreach (['sum' => '16.000000', 'max' => '9.000000', 'last' => '2.000000'] as $aggregation => $expected) {
            [$service, $meter] = $this->meteredService(['aggregation' => $aggregation, 'key' => 'gauge']);
            $svc->record($service, 'gauge', 5, now()->subDays(3));
            $svc->record($service, 'gauge', 2, now()->subDays(1));   // latest by time, inserted second
            $svc->record($service, 'gauge', 9, now()->subDays(2));   // inserted last but not the latest
            $this->assertSame($expected, $svc->currentPeriodSummary($service)->lines->first()->quantity, $aggregation);
            $this->assertSame(3, $svc->currentPeriodSummary($service)->lines->first()->records);
        }
    }

    public function test_recording_is_idempotent_and_fires_one_event(): void
    {
        Event::fake([UsageRecorded::class]);
        [$service] = $this->meteredService();
        $svc = app(UsageService::class);

        $a = $svc->record($service, 'api_calls', '10.5', null, 'req-1', 'test');
        $b = $svc->record($service, 'api_calls', '999', null, 'req-1', 'test');

        $this->assertTrue($a->is($b));
        $this->assertSame(1, UsageRecord::count());
        $this->assertEquals(10.5, $a->refresh()->quantity);
        Event::assertDispatchedTimes(UsageRecorded::class, 1);
        // without a key every call is its own record
        $svc->record($service, 'api_calls', 1);
        $svc->record($service, 'api_calls', 1);
        $this->assertSame(3, UsageRecord::count());
    }

    public function test_recording_validates_meter_quantity_and_service_state(): void
    {
        [$service] = $this->meteredService();
        $svc = app(UsageService::class);

        $this->assertThrowsInvalid(fn () => $svc->record($service, 'nope', 1));
        $this->assertThrowsInvalid(fn () => $svc->record($service, 'api_calls', -1));
        $this->assertThrowsInvalid(fn () => $svc->record($service, 'api_calls', 'abc'));
        $service->update(['status' => 'terminated']);
        $this->expectException(\DomainException::class);
        $svc->record($service->refresh(), 'api_calls', 1);
    }

    public function test_usage_recorded_is_a_webhook_event(): void
    {
        $this->assertSame('usage.recorded', UsageRecorded::eventName());
        $this->assertArrayHasKey('usage.recorded', UsageRecorded::catalog());
    }

    private function assertThrowsInvalid(\Closure $fn): void
    {
        try {
            $fn();
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }
}
