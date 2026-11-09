<?php

namespace Tests\Feature\Usage;

use App\Enums\BillingCycle;
use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use App\Models\UsageMeter;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class UsageTestCase extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Service, 1: UsageMeter} an active service whose product has one meter */
    protected function meteredService(array $meter = [], array $price = ['unit_price' => 200000], int $base = 1000): array
    {
        $product = Product::factory()->priced($base)->create();
        $m = $product->meters()->create($meter + ['key' => 'api_calls', 'name' => 'API calls', 'unit' => 'calls', 'aggregation' => 'sum', 'included_quantity' => 0, 'pricing_model' => 'per_unit']);
        $m->prices()->create(['currency' => 'USD'] + $price);
        ['service' => $service, 'invoice' => $invoice] = app(OrderService::class)->place(Client::factory()->create(), $product, BillingCycle::Monthly);
        app(PaymentService::class)->record($invoice, 'test', $invoice->total, 'first-'.$invoice->id);

        return [$service->refresh(), $m];
    }
}
