<?php

namespace Tests\Feature\Api;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Product;
use App\Models\ServiceLog;
use App\Models\TaxRate;
use App\Plugins\ModuleRegistry;
use App\Services\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakeModule;
use Tests\TestCase;

/** Invoice, quote, payment and service actions that exist in the admin UI, dashboard and activity log. */
class AdminBillingActionsTest extends TestCase
{
    use ApiTestHelpers, RefreshDatabase;

    public function test_invoice_resend_marks_uncollectible_and_public_link(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $draft = $this->makeInvoice(null, false);
        $open = $this->makeInvoice();

        $this->as($admin)->postJson("/api/v1/admin/invoices/{$draft->id}/resend")->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $res = $this->as($admin)->postJson("/api/v1/admin/invoices/{$open->id}/resend")->assertOk()->assertJsonPath('data.status', 'open');
        $this->assertNotNull($res->json('data.sent_at'));
        $this->assertSame(route('invoice.public', $open->uuid), $res->json('data.pay_url'));
        $this->assertNull($this->as($admin)->getJson("/api/v1/admin/invoices/{$draft->id}")->json('data.pay_url'), 'drafts have no public link yet');

        $this->as($admin)->postJson("/api/v1/admin/invoices/{$draft->id}/uncollectible")->assertStatus(422);
        $this->as($admin)->postJson("/api/v1/admin/invoices/{$open->id}/uncollectible")->assertOk()->assertJsonPath('data.status', 'uncollectible');
        $this->as($admin)->postJson("/api/v1/admin/invoices/{$open->id}/resend")->assertStatus(422);
        // an uncollectible invoice can still receive a payment (as in the admin UI)
        $this->as($admin)->postJson("/api/v1/admin/invoices/{$open->id}/payments", ['amount' => 1000, 'method' => 'bank'])->assertCreated();
        $this->assertSame(1000, $open->refresh()->amount_paid);
        $this->assertDatabaseHas('activity_logs', ['action' => 'invoice.uncollectible']);
    }

    public function test_manual_payment_guards_and_refunds(): void
    {
        $admin = $this->admin();
        $invoice = $this->makeInvoice(null, true, 5000);
        $draft = $this->makeInvoice(null, false);

        $this->as($admin)->postJson("/api/v1/admin/invoices/{$draft->id}/payments", ['amount' => 100])->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $this->as($admin)->postJson("/api/v1/admin/invoices/{$invoice->id}/payments", ['amount' => 5001])->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $this->as($admin)->postJson("/api/v1/admin/invoices/{$invoice->id}/payments", ['amount' => 0])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $payment = $this->as($admin)->postJson("/api/v1/admin/invoices/{$invoice->id}/payments", ['amount' => 5000, 'method' => 'cheque', 'notes' => '#12'])->assertCreated()->assertJsonPath('data.gateway', 'manual')->json('data.id');

        // refund without an amount refunds everything still refundable
        $this->as($admin)->postJson("/api/v1/admin/payments/$payment/refund", ['amount' => 1000])->assertCreated()->assertJsonPath('data.amount.amount', 1000);
        $this->as($admin)->postJson("/api/v1/admin/payments/$payment/refund", ['amount' => 9999])->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $this->as($admin)->postJson("/api/v1/admin/payments/$payment/refund")->assertCreated()->assertJsonPath('data.amount.amount', 4000)->assertJsonPath('data.type', 'refund');
        $this->as($admin)->postJson("/api/v1/admin/payments/$payment/refund")->assertStatus(422);
        $this->assertDatabaseHas('activity_logs', ['action' => 'payment.refunded']);

        $this->as($admin)->getJson('/api/v1/admin/payments/summary')->assertOk()->assertJsonPath('data.0.currency', 'USD')->assertJsonPath('data.0.received.amount', 5000)
            ->assertJsonPath('data.0.refunded.amount', 5000)->assertJsonPath('data.0.net.amount', 0);
        $this->as($admin)->getJson('/api/v1/admin/payments/summary?type=refund')->assertJsonPath('data.0.received.amount', 0);
        $this->as($admin)->getJson('/api/v1/admin/payments?from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString().'&gateway=manual')->assertOk()->assertJsonPath('meta.total', 3);
        $this->as($admin)->getJson('/api/v1/admin/payments?from='.now()->addDays(2)->toDateString())->assertJsonCount(0, 'data');
        $this->as($admin)->getJson('/api/v1/admin/payments?type=bogus')->assertStatus(422);
    }

    public function test_invoice_filters_summary_and_draft_client_change(): void
    {
        $admin = $this->admin();
        $acme = Client::factory()->create(['name' => 'Acme Corp', 'country' => 'US']);
        $other = Client::factory()->create(['name' => 'Other Ltd', 'country' => 'DE']);
        TaxRate::create(['name' => 'VAT', 'rate' => 19, 'country' => 'DE', 'is_active' => true]);
        $mine = $this->makeInvoice($acme, true, 2000);
        $this->makeInvoice($other, false);

        $this->as($admin)->getJson('/api/v1/admin/invoices?q=Acme')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->as($admin)->getJson('/api/v1/admin/invoices?status=draft')->assertJsonCount(1, 'data');
        $this->as($admin)->getJson('/api/v1/admin/invoices?status=bogus')->assertStatus(422);
        $this->as($admin)->getJson('/api/v1/admin/invoices?from='.now()->addDays(3)->toDateString())->assertJsonCount(0, 'data');
        $this->as($admin)->getJson('/api/v1/admin/invoices?client_id='.$other->id)->assertJsonCount(1, 'data');

        $summary = $this->as($admin)->getJson('/api/v1/admin/invoices/summary')->assertOk()->assertJsonPath('data.outstanding.0.amount.amount', 2000)->assertJsonPath('data.outstanding.0.invoices', 1)->json('data');
        $this->assertSame([], $summary['overdue']);
        $mine->update(['due_date' => now()->subDays(3)]);
        $this->as($admin)->getJson('/api/v1/admin/invoices/summary')->assertJsonPath('data.overdue.0.amount.amount', 2000);
        $this->as($admin)->getJson('/api/v1/admin/invoices?overdue=1')->assertJsonCount(1, 'data');

        // changing the client of a draft re-applies the new client's tax rate; other fields are optional
        $draft = $this->makeInvoice($acme, false, 10000);
        $this->as($admin)->patchJson("/api/v1/admin/invoices/{$draft->id}", ['client_id' => $other->id])->assertOk()->assertJsonPath('data.client_id', $other->id)->assertJsonPath('data.tax_rate', 19)->assertJsonPath('data.total.amount', 10000);
        $this->as($admin)->patchJson("/api/v1/admin/invoices/{$draft->id}", ['items' => [['description' => 'New', 'unit_price' => 10000]]])->assertOk()->assertJsonPath('data.total.amount', 11900);
        $this->as($admin)->patchJson("/api/v1/admin/invoices/{$draft->id}", ['client_id' => 9999])->assertStatus(422);
        $this->as($admin)->getJson("/api/v1/admin/clients/{$other->id}/tax-rate")->assertOk()->assertJsonPath('data.rate', 19)->assertJsonPath('data.name', 'VAT');
    }

    public function test_quote_actions_duplicate_and_filters(): void
    {
        $admin = $this->admin();
        $client = Client::factory()->create(['name' => 'Quoted Inc']);
        $product = Product::factory()->priced(1500)->create();
        $quote = app(QuoteService::class)->create($client, [['description' => 'Hosting', 'unit_price' => 1500, 'product_id' => $product->id, 'billing_cycle' => 'monthly']], ['title' => 'Big plan']);

        $copy = $this->as($admin)->postJson("/api/v1/admin/quotes/{$quote->id}/duplicate")->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.title', 'Big plan')->assertJsonCount(1, 'data.items');
        $this->assertNotSame($quote->id, $copy->json('data.id'));
        $this->as($admin)->getJson('/api/v1/admin/quotes?q=Quoted')->assertJsonCount(2, 'data');
        $this->as($admin)->getJson('/api/v1/admin/quotes?q=Big')->assertJsonCount(2, 'data');
        $this->as($admin)->getJson('/api/v1/admin/quotes?status=sent')->assertJsonCount(0, 'data');
        $this->as($admin)->getJson('/api/v1/admin/quotes?status=bogus')->assertStatus(422);

        $other = Client::factory()->create();
        $this->as($admin)->patchJson('/api/v1/admin/quotes/'.$copy->json('data.id'), ['client_id' => $other->id, 'title' => 'Moved'])->assertOk()->assertJsonPath('data.client_id', $other->id)->assertJsonPath('data.title', 'Moved');
        $this->as($admin)->postJson('/api/v1/admin/quotes/'.$copy->json('data.id').'/send')->assertOk()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.view_url', fn ($u) => str_contains($u, '/quote/'));
        $this->as($admin)->patchJson('/api/v1/admin/quotes/'.$copy->json('data.id'), ['title' => 'x'])->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $this->as($admin)->postJson('/api/v1/admin/quotes/'.$copy->json('data.id').'/convert')->assertCreated()->assertJsonPath('data.status', 'open');
        $this->as($admin)->deleteJson("/api/v1/admin/quotes/{$quote->id}")->assertNoContent();
        $this->assertDatabaseHas('activity_logs', ['action' => 'quote.converted']);
    }

    public function test_services_manual_creation_logs_secrets_and_audit_trail(): void
    {
        app(ModuleRegistry::class)->register(new FakeModule);
        $admin = $this->admin();
        $client = Client::factory()->create();
        $product = Product::factory()->priced(1500)->create(['module' => 'fake']);

        // default: an order (service + invoice)
        $order = $this->as($admin)->postJson('/api/v1/admin/services', ['client_id' => $client->id, 'product_id' => $product->id, 'billing_cycle' => 'monthly'])->assertCreated();
        $this->assertNotNull($order->json('data.invoice.id'));

        // create_invoice=false: service only, custom price and due date, activated right away
        $res = $this->as($admin)->postJson('/api/v1/admin/services', ['client_id' => $client->id, 'product_id' => $product->id, 'billing_cycle' => 'monthly', 'create_invoice' => false,
            'amount' => 999, 'next_due_date' => '2027-01-15', 'label' => 'manual.example', 'activate' => true])->assertCreated()
            ->assertJsonPath('data.invoice', null)->assertJsonPath('data.service.price.amount', 999)->assertJsonPath('data.service.next_due_date', '2027-01-15')->assertJsonPath('data.service.status', 'active');
        $id = $res->json('data.service.id');
        $this->as($admin)->postJson('/api/v1/admin/services', ['client_id' => $client->id, 'product_id' => $product->id, 'billing_cycle' => 'annually', 'create_invoice' => false])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['amount']]]);

        // logs: provisioning calls are recorded; reveal returns the encrypted secrets and is audited
        ServiceLog::create(['service_id' => $id, 'action' => 'create', 'success' => true, 'message' => 'ok']);
        $this->as($admin)->getJson("/api/v1/admin/services/$id/logs")->assertOk()->assertJsonPath('data.0.action', 'create')->assertJsonStructure(['data' => [['id', 'action', 'success', 'message', 'created_at']], 'meta' => ['total']]);
        $this->assertStringNotContainsString('s3cret', $this->as($admin)->getJson("/api/v1/admin/services/$id")->getContent());
        $this->as($admin)->postJson("/api/v1/admin/services/$id/reveal")->assertOk()->assertJsonPath('data.secrets.password', 's3cret');
        $this->assertDatabaseHas('activity_logs', ['action' => 'service.secrets_revealed', 'subject_id' => $id]);

        // changing price/cycle goes through the module like the UI does
        $this->as($admin)->patchJson("/api/v1/admin/services/$id", ['amount' => 1200])->assertOk()->assertJsonPath('data.price.amount', 1200);
        $this->as($admin)->postJson("/api/v1/admin/services/$id/suspend", ['reason' => 'abuse'])->assertOk();

        // the audit trail: filter by subject for one record's history
        $trail = $this->as($admin)->getJson("/api/v1/admin/activity?subject_type=service&subject_id=$id")->assertOk();
        $actions = array_column($trail->json('data'), 'action');
        $this->assertContains('service.secrets_revealed', $actions);
        $this->assertContains('service.suspend', $actions);
        $this->assertContains('service.updated', $actions);
        $this->assertSame('service', $trail->json('data.0.subject_type'));
        $this->assertSame($admin->email, $trail->json('data.0.user.email'));
        $this->assertSame('api', $trail->json('data.0.properties.via'));
    }

    public function test_activity_log_filters(): void
    {
        $admin = $this->admin();
        $other = $this->staffUser();
        ActivityLog::create(['user_id' => $other->id, 'action' => 'invoice.created', 'description' => 'a']);
        ActivityLog::create(['user_id' => $admin->id, 'action' => 'invoice.paid', 'description' => 'b']);
        ActivityLog::create(['user_id' => $admin->id, 'action' => 'client.updated', 'description' => 'c']);

        $this->as($admin)->getJson('/api/v1/admin/activity?action=invoice.*')->assertOk()->assertJsonPath('meta.total', 2);
        $this->as($admin)->getJson('/api/v1/admin/activity?action=client.updated')->assertJsonPath('meta.total', 1);
        $this->as($admin)->getJson('/api/v1/admin/activity?user_id='.$other->id)->assertJsonPath('meta.total', 1);
        $this->as($admin)->getJson('/api/v1/admin/activity?from='.now()->addDay()->toDateString())->assertJsonPath('meta.total', 0);
        $this->as($admin)->getJson('/api/v1/admin/activity?subject_type=Bad Type!')->assertStatus(422);
        $this->as($admin)->getJson('/api/v1/admin/activity?per_page=2')->assertJsonCount(2, 'data');
        $this->as($this->staffUser(), ['activity.read'])->getJson('/api/v1/admin/activity')->assertOk();
        $this->as($this->staffUser(), ['clients.read'])->getJson('/api/v1/admin/activity')->assertStatus(403);
        $this->as($this->customer())->getJson('/api/v1/admin/activity')->assertStatus(403);
    }

    public function test_dashboard_stats(): void
    {
        $admin = $this->admin();
        $client = Client::factory()->create();
        $paid = $this->makeInvoice($client, true, 3000);
        $this->as($admin)->postJson("/api/v1/admin/invoices/{$paid->id}/payments", ['amount' => 3000])->assertCreated();
        $open = $this->makeInvoice($client, true, 2000);
        $overdue = $this->makeInvoice($client, true, 500);
        $overdue->update(['due_date' => now()->subDays(10)]);
        $this->makeService($client);

        $res = $this->as($admin)->getJson('/api/v1/admin/dashboard')->assertOk()
            ->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.revenue_this_month.amount', 3000)->assertJsonPath('data.outstanding.amount', 2500)
            ->assertJsonPath('data.overdue_invoices', 1)->assertJsonPath('data.active_services', 1)->assertJsonPath('data.new_clients_30d', 1);
        $this->assertCount(12, $res->json('data.monthly_revenue'));
        $this->assertSame(now()->format('Y-m'), $res->json('data.monthly_revenue.11.month'));
        $this->assertSame(3000, $res->json('data.monthly_revenue.11.revenue.amount'));
        $this->assertCount(3, $res->json('data.recent_invoices'));
        $this->assertNotNull($open->id);

        $this->as($this->staffUser())->getJson('/api/v1/admin/dashboard')->assertOk();
        $this->as($this->customer())->getJson('/api/v1/admin/dashboard')->assertStatus(403);
        $this->as($admin, ['dashboard.read'])->getJson('/api/v1/admin/dashboard')->assertOk();
        $this->as($admin, ['invoices.read'])->getJson('/api/v1/admin/dashboard')->assertStatus(403);
    }
}
