<?php

namespace Tests\Feature\Admin;

use App\Enums\BillingCycle;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Product;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_filters_and_totals_and_full_refund_default(): void
    {
        $this->actingAs($this->admin());
        $client = Client::factory()->create();
        $product = Product::factory()->priced(2000)->create();
        ['invoice' => $a] = app(OrderService::class)->place($client, $product, BillingCycle::Monthly);
        ['invoice' => $b] = app(OrderService::class)->place($client, $product, BillingCycle::Monthly);
        $stripe = app(PaymentService::class)->record($a, 'stripe', 2000, 'ch_1');
        app(PaymentService::class)->recordManual($b, 2000, 'cash');

        $this->get(route('admin.payments.index'))->assertOk()->assertSee('ch_1')->assertSee('$40.00');
        $this->get(route('admin.payments.index', ['gateway' => 'manual']))->assertOk()->assertDontSee('ch_1');
        $this->get(route('admin.payments.index', ['type' => 'bogus']))->assertSessionHasErrors('type');

        // refunding without an amount refunds what is left on a manual payment
        $manual = Payment::where('gateway', 'manual')->first();
        $this->post(route('admin.payments.refund', $manual))->assertSessionHas('status');
        $this->assertSame(0, $b->refresh()->amount_paid);
        $this->get(route('admin.payments.index', ['type' => 'refund']))->assertOk()->assertSee('Refund');
        $this->assertNotNull($stripe);
    }

    public function test_view_tabs_filter_payments_and_refunds_with_counts(): void
    {
        $this->actingAs($this->admin());
        $client = Client::factory()->create();
        $product = Product::factory()->priced(2000)->create();
        ['invoice' => $a] = app(OrderService::class)->place($client, $product, BillingCycle::Monthly);
        ['invoice' => $b] = app(OrderService::class)->place($client, $product, BillingCycle::Monthly);
        app(PaymentService::class)->record($a, 'stripe', 2000, 'ch_keep');
        $manual = app(PaymentService::class)->recordManual($b, 2000, 'cash');
        app(PaymentService::class)->refund($manual, 500);

        $this->get(route('admin.payments.index'))->assertOk()->assertSee('Payment views');
        $this->get(route('admin.payments.index', ['view' => 'refunds']))->assertOk()->assertDontSee('ch_keep')->assertSee('Refund');
        $this->get(route('admin.payments.index', ['view' => 'payments']))->assertOk()->assertSee('ch_keep');
        $this->get(route('admin.payments.index', ['view' => 'nope']))->assertSessionHasErrors('view');
    }
}
