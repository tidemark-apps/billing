<?php

namespace Tests\Feature\Portal;

use App\Enums\BillingCycle;
use App\Models\Product;
use App\Services\InvoiceService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_list_has_status_tabs_with_counts_and_a_pay_action_on_unpaid_rows(): void
    {
        $user = $this->customer();
        $product = Product::factory()->priced(1000)->create();
        ['invoice' => $invoice] = app(OrderService::class)->place($user->client, $product, BillingCycle::Monthly);
        app(InvoiceService::class)->issue($invoice);

        $res = $this->actingAs($user)->get(route('portal.invoices.index'))->assertOk();
        $res->assertSee('tab-count', false)->assertSee('Unpaid')->assertSee('Pay $', false);
        $this->get(route('portal.invoices.index', ['status' => 'paid']))->assertOk()->assertSee('No paid invoices');
    }

    public function test_overdue_invoice_shows_a_single_status_pill(): void
    {
        $user = $this->customer();
        $product = Product::factory()->priced(1000)->create();
        ['invoice' => $invoice] = app(OrderService::class)->place($user->client, $product, BillingCycle::Monthly);
        app(InvoiceService::class)->issue($invoice);
        $invoice->update(['due_date' => now()->subDays(5)]);

        $html = $this->actingAs($user)->get(route('portal.invoices.show', $invoice))->assertOk()->assertSee('Amount due')->getContent();
        $this->assertStringNotContainsString('>Unpaid<', $html);
        $this->assertStringNotContainsString('>Open<', $html);
        $this->assertStringContainsString('>Overdue<', $html);
    }

    public function test_auth_password_fields_have_a_visibility_toggle_and_autocomplete(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('data-pw-toggle', false)->assertSee('autocomplete="current-password"', false);
        $this->get(route('register'))->assertOk()->assertSee('autocomplete="new-password"', false);
    }
}
