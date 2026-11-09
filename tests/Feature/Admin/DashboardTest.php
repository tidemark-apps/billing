<?php

namespace Tests\Feature\Admin;

use App\Enums\BillingCycle;
use App\Models\Client;
use App\Models\Product;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_revenue_and_requires_staff(): void
    {
        $this->actingAs($this->customer())->get(route('admin.dashboard'))->assertForbidden();

        $client = Client::factory()->create();
        $product = Product::factory()->priced(12345)->create();
        ['invoice' => $open] = app(OrderService::class)->place($client, $product, BillingCycle::Monthly);
        ['invoice' => $paid] = app(OrderService::class)->place($client, $product, BillingCycle::Monthly);
        app(PaymentService::class)->recordManual($paid, $paid->total);

        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk()
            ->assertSee('$123.45')->assertSee('Revenue this month')->assertSee('Active services');
    }
}
