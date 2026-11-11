<?php

namespace Tests\Feature\System;

use App\Models\Client;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FooterLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_powered_by_link_goes_to_the_billing_landing_page(): void
    {
        $this->actingAs($this->admin())->get('/admin')->assertOk()
            ->assertSee('href="https://hellotidemark.com/billing/"', false)
            ->assertDontSee('tidemarkanalytics.com');
    }

    public function test_the_public_invoice_page_links_to_it_too(): void
    {
        $invoice = app(InvoiceService::class)->create(Client::factory()->create(), [
            ['description' => 'Hosting', 'unit_price' => 1000, 'taxable' => false],
        ], ['issue' => true, 'send' => false]);

        $this->get(route('invoice.public', $invoice->uuid))->assertOk()
            ->assertSee('href="https://hellotidemark.com/billing/"', false)
            ->assertDontSee('tidemarkanalytics.com');
    }
}
