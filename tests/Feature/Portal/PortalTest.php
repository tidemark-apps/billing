<?php

namespace Tests\Feature\Portal;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\ApiToken;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Plugins\ModuleRegistry;
use App\Services\InvoiceService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeModule;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    private function orderFor($user, ?Product $product = null): array
    {
        $product ??= Product::factory()->priced(1000)->create();

        return app(OrderService::class)->place($user->client, $product, BillingCycle::Monthly);
    }

    public function test_guest_sees_catalogue_but_not_hidden_or_inactive_products(): void
    {
        $g = ProductGroup::create(['name' => 'Hosting', 'slug' => 'hosting']);
        Product::factory()->priced(500)->create(['name' => 'Visible Plan', 'product_group_id' => $g->id]);
        Product::factory()->priced(500)->create(['name' => 'Hidden Plan', 'is_visible' => false]);
        Product::factory()->priced(500)->create(['name' => 'Off Plan', 'is_active' => false]);
        $hidden = Product::where('name', 'Hidden Plan')->first();

        $this->get('/store')->assertOk()->assertSee('Visible Plan')->assertSee('Hosting')->assertDontSee('Hidden Plan')->assertDontSee('Off Plan');
        $this->get(route('store.show', $hidden))->assertNotFound();
        $this->get(route('store.show', Product::where('name', 'Visible Plan')->first()))->assertOk()->assertSee('Continue to review');
    }

    public function test_guest_order_redirects_to_login(): void
    {
        $p = Product::factory()->priced(500)->create();
        $this->get(route('portal.order.create', ['product' => $p->slug, 'cycle' => 'monthly']))->assertRedirect(route('login'));
    }

    public function test_order_flow_creates_invoice_and_payment_activates_service(): void
    {
        app(ModuleRegistry::class)->register(new FakeModule);
        $user = $this->customer();
        $product = Product::factory()->priced(1000, BillingCycle::Monthly, 200)->module('fake')->create();

        $this->actingAs($user)->get(route('portal.order.create', ['product' => $product->slug, 'cycle' => 'monthly']))->assertOk()->assertSee('Place order');
        $res = $this->post(route('portal.order.store'), ['product' => $product->slug, 'cycle' => 'monthly']);
        $invoice = $user->client->invoices()->firstOrFail();
        $res->assertRedirect(route('portal.invoices.show', $invoice));
        $this->get(route('portal.invoices.show', $invoice))->assertOk()->assertSee($invoice->number);

        app(PaymentService::class)->record($invoice, 'test', $invoice->total, 'tx');
        $service = $user->client->services()->firstOrFail();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->get(route('portal.services.show', $service))->assertOk()->assertSee('fake-account');
    }

    public function test_label_required_when_product_prompts_for_it(): void
    {
        $user = $this->customer();
        $p = Product::factory()->priced(500)->create(['label_prompt' => 'Domain name']);
        $this->actingAs($user)->post(route('portal.order.store'), ['product' => $p->slug, 'cycle' => 'monthly'])->assertSessionHasErrors('label');
    }

    public function test_cross_client_access_is_404(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        ['service' => $service, 'invoice' => $invoice] = $this->orderFor($b);
        $quote = app(QuoteService::class)->create($b->client, [['description' => 'x', 'unit_price' => 100]], ['send' => true]);

        $this->actingAs($a);
        $this->get(route('portal.invoices.show', $invoice))->assertNotFound();
        $this->get(route('portal.services.show', $service))->assertNotFound();
        $this->post(route('portal.services.reveal', $service))->assertNotFound();
        $this->post(route('portal.services.cancel', $service))->assertNotFound();
        $this->post(route('portal.services.action', [$service, 'login']))->assertNotFound();
        $this->get(route('portal.quotes.show', $quote))->assertNotFound();
        $this->post(route('portal.quotes.respond', $quote), ['decision' => 'accept', 'name' => 'Eve'])->assertNotFound();
        $this->assertSame('sent', $quote->refresh()->status->value);
    }

    public function test_draft_invoices_and_quotes_are_hidden(): void
    {
        $u = $this->customer();
        $draft = app(InvoiceService::class)->create($u->client, [['description' => 'x', 'unit_price' => 100]]);
        $quote = app(QuoteService::class)->create($u->client, [['description' => 'x', 'unit_price' => 100]]);
        $this->actingAs($u)->get(route('portal.invoices.show', $draft))->assertNotFound();
        $this->get(route('portal.quotes.show', $quote))->assertNotFound();
    }

    public function test_quote_accept_creates_invoice(): void
    {
        $u = $this->customer();
        $quote = app(QuoteService::class)->create($u->client, [['description' => 'Consulting', 'unit_price' => 5000]], ['send' => true]);
        $this->actingAs($u)->get(route('portal.quotes.show', $quote))->assertOk()->assertSee('Accept quote');
        $this->post(route('portal.quotes.respond', $quote), ['decision' => 'accept'])->assertSessionHasErrors('name');
        $res = $this->post(route('portal.quotes.respond', $quote), ['decision' => 'accept', 'name' => 'Jo Client']);
        $invoice = $quote->refresh()->invoice;
        $this->assertNotNull($invoice);
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $res->assertRedirect(route('portal.invoices.show', $invoice));
    }

    public function test_dashboard_and_lists_render(): void
    {
        $u = $this->customer();
        $this->orderFor($u);
        $this->actingAs($u);
        foreach (['portal.dashboard', 'portal.services.index', 'portal.invoices.index', 'portal.quotes.index', 'portal.store', 'portal.profile', 'portal.api-tokens'] as $r) {
            $this->get(route($r))->assertOk();
        }
    }

    public function test_cancel_schedules_at_period_end(): void
    {
        $u = $this->customer();
        ['service' => $service, 'invoice' => $invoice] = $this->orderFor($u);
        app(PaymentService::class)->record($invoice, 'test', $invoice->total, 'x');
        $this->actingAs($u)->post(route('portal.services.cancel', $service), ['reason' => 'moving'])->assertRedirect();
        $this->assertTrue($service->refresh()->cancel_at_period_end);
    }

    public function test_profile_update_and_currency_lock(): void
    {
        $u = $this->customer();
        $this->actingAs($u)->post(route('portal.profile.update'), ['name' => 'New Name', 'email' => $u->email, 'company_name' => 'Acme', 'country' => 'GB', 'currency' => 'EUR'])->assertSessionHasNoErrors();
        $this->assertSame('Acme', $u->client->refresh()->company_name);
        $this->assertSame('EUR', $u->client->currency);
        app(InvoiceService::class)->create($u->client, [['description' => 'x', 'unit_price' => 100]]);
        $this->post(route('portal.profile.update'), ['name' => 'New Name', 'email' => $u->email, 'currency' => 'GBP']);
        $this->assertSame('EUR', $u->client->refresh()->currency);
    }

    public function test_password_change_requires_current_password(): void
    {
        $u = $this->customer();
        $this->actingAs($u)->post(route('portal.profile.password'), ['current_password' => 'wrong', 'password' => 'newpassword1', 'password_confirmation' => 'newpassword1'])->assertSessionHasErrors('current_password');
        $this->post(route('portal.profile.password'), ['current_password' => 'password', 'password' => 'newpassword1', 'password_confirmation' => 'newpassword1'])->assertSessionHasNoErrors();
    }

    public function test_api_token_create_and_revoke(): void
    {
        $u = $this->customer();
        $other = $this->customer();
        [$foreign] = ApiToken::issue($other, 'theirs');
        $this->actingAs($u)->post(route('portal.api-tokens.store'), ['name' => 'site'])->assertSessionHas('new_token');
        $token = $u->apiTokens()->firstOrFail();
        $this->delete(route('portal.api-tokens.destroy', $foreign))->assertNotFound();
        $this->delete(route('portal.api-tokens.destroy', $token))->assertRedirect();
        $this->assertSame(0, $u->apiTokens()->count());
    }
}
