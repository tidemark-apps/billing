<?php

namespace Tests\Feature\System;

use App\Enums\BillingCycle;
use App\Models\ApiToken;
use App\Models\Client;
use App\Models\Product;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_token_shows_it_once_and_stores_only_a_hash(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post('/admin/api/tokens', ['name' => 'Sync', 'scope' => 'custom', 'abilities' => ['clients.read', 'bogus']])->assertRedirect();
        $plain = session('new_token');

        $this->assertStringStartsWith('tmb_', $plain);
        $token = ApiToken::findPlain($plain);
        $this->assertSame(['clients.read'], $token->abilities);
        $this->assertNotSame($plain, $token->token_hash);
        $this->actingAs($admin)->get('/admin/api')->assertOk()->assertSee($plain); // the redirect target shows it once
        $this->actingAs($admin)->get('/admin/api')->assertOk()->assertDontSee($plain); // and never again
    }

    public function test_webhook_lifecycle_and_signed_test_delivery(): void
    {
        Http::fake(['https://hooks.test/*' => Http::response('ok', 200)]);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/api/webhooks', ['url' => 'https://hooks.test/in', 'events' => ['invoice.paid']])->assertRedirect();
        $secret = session('new_secret');
        $hook = WebhookEndpoint::first();
        $this->assertSame($secret, $hook->secret);
        $this->assertTrue($hook->listensTo('invoice.paid'));
        $this->assertFalse($hook->listensTo('client.created'));

        $this->actingAs($admin)->post("/admin/api/webhooks/{$hook->id}/test")->assertSessionHas('status');

        Http::assertSent(function ($request) use ($secret) {
            $expected = 'sha256='.hash_hmac('sha256', $request->header('X-Billing-Timestamp')[0].'.'.$request->body(), $secret);

            return $request->hasHeader('X-Billing-Signature', $expected) && $request['event'] === 'ping';
        });
    }

    public function test_domain_events_are_delivered_to_subscribed_webhooks_only(): void
    {
        Http::fake(['https://hooks.test/*' => Http::response('ok', 200)]);
        WebhookEndpoint::create(['url' => 'https://hooks.test/paid', 'secret' => 's', 'events' => ['invoice.paid']]);
        WebhookEndpoint::create(['url' => 'https://hooks.test/clients', 'secret' => 's', 'events' => ['client.created']]);

        $client = Client::factory()->create();
        $product = Product::factory()->priced(500)->create();
        ['invoice' => $invoice] = app(OrderService::class)->place($client, $product, BillingCycle::Monthly);
        app(PaymentService::class)->recordManual($invoice, $invoice->total);

        Http::assertSent(fn ($r) => $r->url() === 'https://hooks.test/paid' && $r['event'] === 'invoice.paid' && $r['data']['number'] === $invoice->number);
        Http::assertNotSent(fn ($r) => $r->url() === 'https://hooks.test/clients');
    }

    public function test_staff_cannot_create_tokens_or_webhooks(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post('/admin/api/tokens', ['name' => 'x', 'scope' => 'full'])->assertForbidden();
        $this->actingAs($staff)->post('/admin/api/webhooks', ['url' => 'https://x.test'])->assertForbidden();
    }
}
