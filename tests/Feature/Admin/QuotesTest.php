<?php

namespace Tests\Feature\Admin;

use App\Enums\BillingCycle;
use App\Enums\QuoteStatus;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotesTest extends TestCase
{
    use RefreshDatabase;

    private function payload(Client $client, Product $product, string $action = 'draft'): array
    {
        return [
            'client_id' => $client->id,
            'currency' => 'USD',
            'title' => 'Hosting proposal',
            'valid_until' => now()->addDays(10)->toDateString(),
            'action' => $action,
            'items' => [
                ['description' => 'Hosting', 'quantity' => '1', 'unit_price' => '15.00', 'taxable' => '1', 'product_id' => $product->id, 'billing_cycle' => 'monthly', 'label' => 'example.com'],
                ['description' => 'Setup work', 'quantity' => '2', 'unit_price' => '40.00', 'taxable' => '1'],
            ],
        ];
    }

    public function test_create_send_accept_and_convert_creates_services(): void
    {
        $this->actingAs($this->admin());
        $client = Client::factory()->create();
        $product = Product::factory()->priced(1500, BillingCycle::Monthly)->create();

        $this->get(route('admin.quotes.create'))->assertOk();
        $this->post(route('admin.quotes.store'), $this->payload($client, $product))->assertRedirect();
        $quote = Quote::firstOrFail();
        $this->assertSame(QuoteStatus::Draft, $quote->status);
        $this->assertSame(9500, $quote->total);

        $this->post(route('admin.quotes.accept', $quote))->assertSessionHas('error');
        $this->post(route('admin.quotes.send', $quote))->assertSessionHas('status');
        $this->assertSame(QuoteStatus::Sent, $quote->refresh()->status);
        $this->get(route('admin.quotes.show', $quote))->assertOk()->assertSee($quote->number);

        $this->post(route('admin.quotes.accept', $quote))->assertSessionHas('status');
        $quote->refresh();
        $this->assertSame(QuoteStatus::Converted, $quote->status);
        $this->assertNotNull($quote->invoice_id);
        $service = Service::firstOrFail();
        $this->assertSame(ServiceStatus::Pending, $service->status);
        $this->assertSame('example.com', $service->label);
        $this->assertStringContainsString('(on behalf of client)', $quote->accepted_by);
    }

    public function test_convert_a_draft_directly(): void
    {
        $this->actingAs($this->admin());
        $client = Client::factory()->create();
        $product = Product::factory()->priced(1500)->create();
        $this->post(route('admin.quotes.store'), $this->payload($client, $product));
        $quote = Quote::firstOrFail();

        $response = $this->post(route('admin.quotes.convert', $quote));
        $response->assertRedirect(route('admin.invoices.show', $quote->refresh()->invoice_id));
        $this->assertSame(1, Service::count());
    }

    public function test_decline_and_duplicate(): void
    {
        $this->actingAs($this->admin());
        $client = Client::factory()->create();
        $product = Product::factory()->priced(1500)->create();
        $this->post(route('admin.quotes.store'), $this->payload($client, $product, 'send'));
        $quote = Quote::firstOrFail();

        $this->post(route('admin.quotes.decline', $quote))->assertSessionHas('status');
        $this->assertSame(QuoteStatus::Declined, $quote->refresh()->status);

        $this->post(route('admin.quotes.duplicate', $quote))->assertRedirect();
        $copy = Quote::latest('id')->first();
        $this->assertNotSame($quote->id, $copy->id);
        $this->assertSame(QuoteStatus::Draft, $copy->status);
        $this->assertSame($quote->total, $copy->total);
        $this->assertSame(2, $copy->items()->count());
        $this->assertSame($product->id, $copy->items()->first()->product_id);
    }

    public function test_only_drafts_can_be_edited_or_deleted(): void
    {
        $this->actingAs($this->admin());
        $client = Client::factory()->create();
        $product = Product::factory()->priced(1500)->create();
        $this->post(route('admin.quotes.store'), $this->payload($client, $product, 'send'));
        $quote = Quote::firstOrFail();

        $this->get(route('admin.quotes.edit', $quote))->assertRedirect(route('admin.quotes.show', $quote));
        $this->put(route('admin.quotes.update', $quote), $this->payload($client, $product))->assertSessionHas('error');
        $this->delete(route('admin.quotes.destroy', $quote))->assertSessionHas('error');

        $this->post(route('admin.quotes.store'), $this->payload($client, $product));
        $draft = Quote::latest('id')->first();
        $this->get(route('admin.quotes.edit', $draft))->assertOk()->assertSee('example.com');
        $this->delete(route('admin.quotes.destroy', $draft))->assertRedirect(route('admin.quotes.index'));
        $this->assertNull(Quote::find($draft->id));
        $this->get(route('admin.quotes.index'))->assertOk();
    }

    public function test_status_tabs_filter_the_list_and_show_counts(): void
    {
        $this->actingAs($this->admin());
        $client = Client::factory()->create();
        $product = Product::factory()->create();
        $this->post(route('admin.quotes.store'), $this->payload($client, $product, 'draft'));
        $this->post(route('admin.quotes.store'), $this->payload($client, $product, 'send'));

        $this->get(route('admin.quotes.index'))->assertOk()->assertSee('Quote views');
        $sent = Quote::where('status', QuoteStatus::Sent->value)->first();
        $draft = Quote::where('status', QuoteStatus::Draft->value)->first();
        $this->get(route('admin.quotes.index', ['view' => 'sent']))->assertOk()->assertSee($sent->number)->assertDontSee($draft->number);
        $this->get(route('admin.quotes.index', ['view' => 'bogus']))->assertSessionHasErrors('view');
    }
}
