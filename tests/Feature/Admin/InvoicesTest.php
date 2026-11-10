<?php

namespace Tests\Feature\Admin;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\TaxRate;
use App\Plugins\ModuleRegistry;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeModule;
use Tests\TestCase;

class InvoicesTest extends TestCase
{
    use RefreshDatabase;

    private function payload(Client $client, string $action = 'draft'): array
    {
        return [
            'client_id' => $client->id,
            'currency' => 'USD',
            'action' => $action,
            'items' => [
                ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '50.00', 'taxable' => '1'],
                ['description' => 'Domain', 'quantity' => '1', 'unit_price' => '10.50', 'taxable' => '0'],
            ],
        ];
    }

    public function test_customers_cannot_reach_the_admin_pages(): void
    {
        $this->actingAs($this->customer());

        $this->get(route('admin.invoices.index'))->assertForbidden();
        $this->get(route('admin.quotes.index'))->assertForbidden();
        $this->get(route('admin.payments.index'))->assertForbidden();
        $this->get(route('admin.tax-rates.index'))->assertForbidden();
    }

    public function test_pages_render_for_staff(): void
    {
        $this->actingAs($this->admin());
        $client = Client::factory()->create();
        Product::factory()->priced(1500, BillingCycle::Monthly)->create();

        $this->get(route('admin.invoices.index'))->assertOk()->assertSee('No invoices found');
        $this->get(route('admin.invoices.create', ['client' => $client->id]))->assertOk()->assertSee('Line items')->assertSee('Add from catalogue');
        $this->get(route('admin.invoices.tax-rate', $client))->assertOk()->assertJsonStructure(['rate', 'name', 'currency']);
    }

    public function test_create_draft_then_issue_it(): void
    {
        $this->actingAs($this->admin());
        $client = Client::factory()->create();
        TaxRate::create(['name' => 'VAT', 'rate' => 10, 'country' => 'US']);

        $this->post(route('admin.invoices.store'), $this->payload($client))->assertRedirect();
        $invoice = Invoice::firstOrFail();
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertNull($invoice->number);
        $this->assertSame(11050, $invoice->subtotal);
        $this->assertSame(1000, $invoice->tax_total);

        $this->get(route('admin.invoices.edit', $invoice))->assertOk();
        $this->post(route('admin.invoices.issue', $invoice))->assertRedirect();
        $this->assertSame(InvoiceStatus::Open, $invoice->refresh()->status);
        $this->assertStringStartsWith('INV-', $invoice->number);
        $this->get(route('admin.invoices.show', $invoice))->assertOk()->assertSee($invoice->number);
    }

    public function test_save_and_issue_in_one_step(): void
    {
        $this->actingAs($this->admin());
        $this->post(route('admin.invoices.store'), $this->payload(Client::factory()->create(), 'issue'))->assertRedirect();

        $this->assertSame(InvoiceStatus::Open, Invoice::firstOrFail()->status);
    }

    public function test_only_drafts_can_be_edited_or_deleted(): void
    {
        $this->actingAs($this->admin());
        $client = Client::factory()->create();
        $this->post(route('admin.invoices.store'), $this->payload($client, 'issue'));
        $invoice = Invoice::firstOrFail();

        $this->get(route('admin.invoices.edit', $invoice))->assertRedirect(route('admin.invoices.show', $invoice));
        $this->put(route('admin.invoices.update', $invoice), $this->payload($client))->assertSessionHas('error');
        $this->delete(route('admin.invoices.destroy', $invoice))->assertSessionHas('error');
        $this->assertSame(1, Invoice::count());

        $this->post(route('admin.invoices.store'), $this->payload($client));
        $draft = Invoice::latest('id')->first();
        $this->put(route('admin.invoices.update', $draft), ['items' => [['description' => 'Changed', 'unit_price' => '5']]] + $this->payload($client))->assertRedirect();
        $this->assertSame('Changed', $draft->items()->first()->description);
        $this->delete(route('admin.invoices.destroy', $draft))->assertRedirect(route('admin.invoices.index'));
        $this->assertNull(Invoice::find($draft->id));
    }

    public function test_validation_requires_at_least_one_item(): void
    {
        $this->actingAs($this->admin());
        $this->post(route('admin.invoices.store'), ['client_id' => Client::factory()->create()->id, 'currency' => 'USD', 'items' => []])->assertSessionHasErrors('items');
        $this->post(route('admin.invoices.store'), ['client_id' => Client::factory()->create()->id, 'currency' => 'USD', 'items' => [['description' => 'x', 'unit_price' => '-3']]])->assertSessionHasErrors('items.0.unit_price');
    }

    public function test_manual_payment_marks_paid_and_provisions_the_service(): void
    {
        app(ModuleRegistry::class)->register(new FakeModule);
        $this->actingAs($this->admin());
        $client = Client::factory()->create();
        $product = Product::factory()->priced(2000)->module('fake')->create();
        ['service' => $service, 'invoice' => $invoice] = app(OrderService::class)->place($client, $product, BillingCycle::Monthly);

        $this->post(route('admin.invoices.payments.store', $invoice), ['amount' => '9999', 'method' => 'Cash'])->assertSessionHasErrors('amount');
        $this->post(route('admin.invoices.payments.store', $invoice), ['amount' => '20.00', 'method' => 'Bank transfer'])->assertSessionHas('status');

        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->get(route('admin.invoices.show', $invoice))->assertOk()->assertSee('Bank transfer');
    }

    public function test_refund_and_void_rules(): void
    {
        $this->actingAs($this->admin());
        $client = Client::factory()->create();
        $this->post(route('admin.invoices.store'), $this->payload($client, 'issue'));
        $invoice = Invoice::firstOrFail();
        $this->post(route('admin.invoices.payments.store', $invoice), ['amount' => '50.00', 'method' => 'Cash']);

        $this->post(route('admin.invoices.void', $invoice))->assertSessionHas('error');
        $this->assertSame(InvoiceStatus::Open, $invoice->refresh()->status);

        $payment = $invoice->payments()->first();
        $this->post(route('admin.payments.refund', $payment), ['amount' => '20.00'])->assertSessionHas('status');
        $this->assertSame(3000, $invoice->refresh()->amount_paid);
        $this->post(route('admin.payments.refund', $payment), ['amount' => '99.00'])->assertSessionHas('error');
        $this->get(route('admin.payments.index'))->assertOk()->assertSee('Refund');

        $this->post(route('admin.invoices.store'), $this->payload($client, 'issue'));
        $unpaid = Invoice::latest('id')->first();
        $this->post(route('admin.invoices.void', $unpaid))->assertSessionHas('status');
        $this->assertSame(InvoiceStatus::Void, $unpaid->refresh()->status);
    }

    public function test_overpaying_shows_an_inline_error_on_the_amount_field(): void
    {
        $this->actingAs($this->admin());
        $client = Client::factory()->create();
        $this->post(route('admin.invoices.store'), $this->payload($client, 'issue'));
        $invoice = Invoice::firstOrFail();

        $this->from(route('admin.invoices.show', $invoice))->post(route('admin.invoices.payments.store', $invoice), ['amount' => '99999', 'method' => 'Cash'])
            ->assertSessionHasErrors('amount');
        $this->get(route('admin.invoices.show', $invoice))->assertOk()->assertSee('Record payment')->assertSee('Activity');
    }

    public function test_missing_line_description_gets_a_fix_it_message(): void
    {
        $this->actingAs($this->admin());
        $client = Client::factory()->create();
        $payload = $this->payload($client);
        $payload['items'][0]['description'] = '';

        $this->post(route('admin.invoices.store'), $payload)->assertSessionHasErrors(['items.0.description' => 'Describe this line.']);
    }
}
