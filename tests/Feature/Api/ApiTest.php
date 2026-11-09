<?php

namespace Tests\Feature\Api;

use App\Enums\BillingCycle;
use App\Http\Resources\ServerResource;
use App\Models\ApiToken;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Plugins\AbstractGateway;
use App\Plugins\GatewayRegistry;
use App\Plugins\ModuleRegistry;
use App\Services\OrderService;
use App\Services\QuoteService;
use App\Support\CheckoutContext;
use App\Support\CheckoutResult;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fakes\FakeServerModule;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $user, ?array $abilities = null): static
    {
        [, $plain] = ApiToken::issue($user, 'test', $abilities);

        return $this->withToken($plain);
    }

    private function fakeGateway(): void
    {
        $gw = new class extends AbstractGateway
        {
            public function key(): string
            {
                return 'fakepay';
            }

            public function label(): string
            {
                return 'Fake Pay';
            }

            public function fields(): array
            {
                return [];
            }

            public function checkout(Invoice $invoice, CheckoutContext $context): CheckoutResult
            {
                return CheckoutResult::redirect('https://pay.test/'.$invoice->uuid);
            }

            public function webhook(Request $request): Response
            {
                return response('ok');
            }
        };
        app(GatewayRegistry::class)->register($gw);
        Settings::set('gateway.fakepay.enabled', true);
    }

    private function order(User $user, int $price = 1000): array
    {
        $product = Product::factory()->priced($price)->create();

        return app(OrderService::class)->place($user->client, $product, BillingCycle::Monthly, ['label' => 'a.example']);
    }

    public function test_token_flow_and_me(): void
    {
        $user = User::factory()->withClient()->create(['email' => 'jane@example.com']);

        $this->postJson('/api/v1/auth/token', ['email' => 'jane@example.com', 'password' => 'wrong'])->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
        $token = $this->postJson('/api/v1/auth/token', ['email' => 'jane@example.com', 'password' => 'password'])->assertCreated()->assertJsonPath('data.token_type', 'Bearer')->json('data.token');

        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', 'jane@example.com')->assertJsonPath('data.client.currency', 'USD');
        $this->withToken($token)->patchJson('/api/v1/me', ['client' => ['company_name' => 'Acme']])->assertOk()->assertJsonPath('data.client.company_name', 'Acme');
        $this->withToken($token)->deleteJson('/api/v1/auth/token')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
        $this->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_register_creates_client_and_token(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'New Co', 'email' => 'new@example.com', 'password' => 'longenough1'])
            ->assertCreated()->assertJsonStructure(['data' => ['token', 'user' => ['client' => ['id']]]]);
        $this->assertDatabaseHas('clients', ['email' => 'new@example.com']);
        $this->postJson('/api/v1/auth/register', ['name' => 'x'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['details' => ['email', 'password']]]);
    }

    public function test_public_catalog_only_shows_visible_active_products(): void
    {
        $shown = Product::factory()->priced(500)->create(['name' => 'Shown']);
        Product::factory()->priced(500)->create(['is_visible' => false]);
        Product::factory()->priced(500)->create(['is_active' => false]);

        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.prices.0.amount.amount', 500)->assertJsonPath('data.0.prices.0.amount.currency', 'USD')
            ->assertJsonStructure(['data', 'links', 'meta' => ['total', 'per_page']]);
        $this->getJson('/api/v1/products/'.$shown->slug)->assertOk()->assertJsonPath('data.name', 'Shown');
        $this->getJson('/api/v1/products/'.$shown->id)->assertOk();
        $this->getJson('/api/v1/products/nope')->assertStatus(404)->assertJsonPath('error.code', 'not_found');
        $this->getJson('/api/v1/products')->assertJsonMissingPath('data.0.module');
    }

    public function test_ordering_creates_invoice_and_pay_url_and_checkout(): void
    {
        $this->fakeGateway();
        $user = $this->customer();
        $product = Product::factory()->priced(2000)->create();

        $res = $this->as($user)->postJson('/api/v1/orders', ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'label' => 'shop.example'])->assertCreated();
        $res->assertJsonPath('data.service.status', 'pending')->assertJsonPath('data.invoice.status', 'open')->assertJsonPath('data.invoice.total.amount', 2000);
        $this->assertStringContainsString('/invoice/', $res->json('data.pay_url'));
        $uuid = $res->json('data.invoice.uuid');

        $this->as($user)->getJson('/api/v1/gateways')->assertOk()->assertJsonPath('data.0.key', 'fakepay');
        $this->as($user)->postJson("/api/v1/invoices/$uuid/pay", ['gateway' => 'fakepay'])->assertOk()->assertJsonPath('data.checkout_url', 'https://pay.test/'.$uuid);
        $this->as($user)->postJson("/api/v1/invoices/$uuid/pay", ['gateway' => 'nothing'])->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $this->as($user)->postJson('/api/v1/orders', ['product_id' => $product->id, 'billing_cycle' => 'annually'])->assertStatus(422);
        $hidden = Product::factory()->priced(1)->create(['is_visible' => false]);
        $this->as($user)->postJson('/api/v1/orders', ['product_id' => $hidden->id, 'billing_cycle' => 'monthly'])->assertNotFound();
    }

    public function test_tenant_isolation(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        ['service' => $svcB, 'invoice' => $invB] = $this->order($b);
        $quoteB = app(QuoteService::class)->create($b->client, [['description' => 'Work', 'unit_price' => 5000]], ['send' => true]);
        $this->order($a);

        $this->as($a)->getJson("/api/v1/invoices/{$invB->uuid}")->assertNotFound();
        $this->as($a)->getJson("/api/v1/services/{$svcB->uuid}")->assertNotFound();
        $this->as($a)->getJson("/api/v1/quotes/{$quoteB->uuid}")->assertNotFound();
        $this->as($a)->postJson("/api/v1/quotes/{$quoteB->uuid}/accept")->assertNotFound();
        $this->as($a)->postJson("/api/v1/services/{$svcB->uuid}/cancel")->assertNotFound();
        $this->as($a)->getJson('/api/v1/invoices')->assertOk()->assertJsonCount(1, 'data');
        $this->as($a)->getJson('/api/v1/services')->assertJsonCount(1, 'data');
        $this->as($b)->getJson("/api/v1/invoices/{$invB->uuid}")->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_quote_accept_converts_to_invoice(): void
    {
        $user = $this->customer();
        $quote = app(QuoteService::class)->create($user->client, [['description' => 'Consulting', 'unit_price' => 7500]], ['send' => true]);

        $this->as($user)->postJson("/api/v1/quotes/{$quote->uuid}/accept", ['name' => 'Jane'])->assertOk()->assertJsonPath('data.status', 'converted')->assertJsonPath('data.invoice.total.amount', 7500);
        $this->as($user)->postJson("/api/v1/quotes/{$quote->uuid}/decline")->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
    }

    public function test_abilities_are_enforced(): void
    {
        $user = $this->customer();
        $this->order($user);

        $this->as($user, ['services.read'])->getJson('/api/v1/services')->assertOk();
        $this->as($user, ['services.read'])->getJson('/api/v1/invoices')->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
        $this->as($user, ['invoices.read'])->postJson('/api/v1/orders', [])->assertStatus(403);

        $admin = $this->admin();
        $this->as($admin, ['clients.read'])->getJson('/api/v1/admin/clients')->assertOk();
        $this->as($admin, ['clients.read'])->postJson('/api/v1/admin/clients', ['name' => 'x', 'email' => 'x@example.com'])->assertStatus(403);
    }

    public function test_pagination_and_filters(): void
    {
        $admin = $this->admin();
        Client::factory()->count(5)->create();
        Client::factory()->create(['name' => 'Zed Findable']);

        $this->as($admin)->getJson('/api/v1/admin/clients?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 6)->assertJsonPath('meta.per_page', 2);
        $this->as($admin)->getJson('/api/v1/admin/clients?per_page=2&page=3')->assertJsonCount(2, 'data');
        $this->as($admin)->getJson('/api/v1/admin/clients?q=Findable')->assertJsonCount(1, 'data');
        $this->as($admin)->getJson('/api/v1/admin/clients?per_page=9999')->assertJsonPath('meta.per_page', 100);
    }

    public function test_auth_matrix_for_admin_routes(): void
    {
        $this->getJson('/api/v1/admin/clients')->assertStatus(401);
        $this->as($this->customer())->getJson('/api/v1/admin/clients')->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
        $this->as($this->admin())->getJson('/api/v1/services')->assertStatus(403);
        $this->as($this->admin())->getJson('/api/v1/admin/clients')->assertOk();
        $this->as(User::factory()->staff()->create())->getJson('/api/v1/admin/clients')->assertOk();
        $client = Client::factory()->create();
        $this->as(User::factory()->staff()->create())->deleteJson("/api/v1/admin/clients/{$client->id}")->assertStatus(403);
        $this->as($this->admin())->deleteJson("/api/v1/admin/clients/{$client->id}")->assertNoContent();
    }

    public function test_admin_clients_and_products_crud(): void
    {
        app(ModuleRegistry::class)->register(new FakeServerModule);
        $admin = $this->admin();
        $res = $this->as($admin)->postJson('/api/v1/admin/clients', ['name' => 'Acme', 'email' => 'acme@example.com', 'password' => 'longenough1', 'country' => 'us'])->assertCreated()->assertJsonPath('data.address.country', 'US');
        $id = $res->json('data.id');
        $this->assertDatabaseHas('users', ['email' => 'acme@example.com', 'client_id' => $id]);
        $this->as($admin)->patchJson("/api/v1/admin/clients/$id", ['company_name' => 'Acme Inc'])->assertOk()->assertJsonPath('data.company_name', 'Acme Inc');
        $this->as($admin)->postJson("/api/v1/admin/clients/$id/users", ['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'longenough1'])->assertCreated();

        $p = $this->as($admin)->postJson('/api/v1/admin/products', ['name' => 'VPS Small', 'module' => 'fakeserver', 'module_config' => ['package' => 'small'], 'prices' => [['cycle' => 'monthly', 'currency' => 'usd', 'amount' => 900, 'setup_fee' => 100]]])->assertCreated()
            ->assertJsonPath('data.slug', 'vps-small')->assertJsonPath('data.prices.0.setup_fee.amount', 100)->assertJsonPath('data.module', 'fakeserver');
        $pid = $p->json('data.id');
        $this->as($admin)->patchJson("/api/v1/admin/products/$pid", ['prices' => [['cycle' => 'annually', 'currency' => 'USD', 'amount' => 9000]]])->assertOk()->assertJsonCount(1, 'data.prices');
        $this->as($admin)->postJson('/api/v1/admin/products', ['name' => 'Bad', 'prices' => [['cycle' => 'weekly', 'currency' => 'USD', 'amount' => 1]]])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->as($admin)->deleteJson("/api/v1/admin/products/$pid")->assertNoContent();
    }

    public function test_admin_invoice_lifecycle_and_payments(): void
    {
        $admin = $this->admin();
        $client = Client::factory()->create();

        $inv = $this->as($admin)->postJson('/api/v1/admin/invoices', ['client_id' => $client->id, 'items' => [['description' => 'Setup', 'unit_price' => 10000, 'quantity' => 2]]])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total.amount', 20000);
        $id = $inv->json('data.id');
        $this->as($admin)->patchJson("/api/v1/admin/invoices/$id", ['items' => [['description' => 'Setup', 'unit_price' => 5000]]])->assertOk()->assertJsonPath('data.total.amount', 5000);
        $this->as($admin)->postJson("/api/v1/admin/invoices/$id/payments", ['amount' => 5000])->assertStatus(422);
        $this->as($admin)->postJson("/api/v1/admin/invoices/$id/issue")->assertOk()->assertJsonPath('data.status', 'open');
        $this->as($admin)->patchJson("/api/v1/admin/invoices/$id", ['notes' => 'x'])->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $pay = $this->as($admin)->postJson("/api/v1/admin/invoices/$id/payments", ['amount' => 5000, 'method' => 'bank'])->assertCreated()->assertJsonPath('data.gateway', 'manual');
        $this->as($admin)->getJson("/api/v1/admin/invoices/$id")->assertJsonPath('data.status', 'paid');
        $this->as($admin)->postJson('/api/v1/admin/payments/'.$pay->json('data.id').'/refund', ['amount' => 1000])->assertCreated()->assertJsonPath('data.type', 'refund');
        $this->as($admin)->getJson('/api/v1/admin/payments?type=refund')->assertJsonCount(1, 'data');
        $this->as($admin)->postJson("/api/v1/admin/invoices/$id/void")->assertStatus(422);

        $draft = $this->as($admin)->postJson('/api/v1/admin/invoices', ['client_id' => $client->id, 'items' => [['description' => 'x', 'unit_price' => 1]]])->json('data.id');
        $this->as($admin)->deleteJson("/api/v1/admin/invoices/$draft")->assertNoContent();
        $this->as($admin)->getJson('/api/v1/admin/invoices?status=paid')->assertJsonCount(1, 'data');
    }

    public function test_admin_quotes_and_services(): void
    {
        $admin = $this->admin();
        $client = Client::factory()->create();
        $product = Product::factory()->priced(1500)->create();

        $q = $this->as($admin)->postJson('/api/v1/admin/quotes', ['client_id' => $client->id, 'items' => [['description' => 'Hosting', 'unit_price' => 1500, 'product_id' => $product->id, 'billing_cycle' => 'monthly']]])->assertCreated();
        $qid = $q->json('data.id');
        $this->as($admin)->postJson("/api/v1/admin/quotes/$qid/send")->assertOk()->assertJsonPath('data.status', 'sent');
        $invoice = $this->as($admin)->postJson("/api/v1/admin/quotes/$qid/convert")->assertCreated();
        $this->assertSame('open', $invoice->json('data.status'));
        $this->assertSame(1, Service::where('client_id', $client->id)->count());

        $s = $this->as($admin)->postJson('/api/v1/admin/services', ['client_id' => $client->id, 'product_id' => $product->id, 'billing_cycle' => 'monthly', 'label' => 'x.example'])->assertCreated();
        $sid = $s->json('data.service.id');
        $this->as($admin)->postJson("/api/v1/admin/services/$sid/activate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->as($admin)->postJson("/api/v1/admin/services/$sid/suspend", ['reason' => 'abuse'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->as($admin)->postJson("/api/v1/admin/services/$sid/unsuspend")->assertOk()->assertJsonPath('data.status', 'active');
        $this->as($admin)->postJson("/api/v1/admin/services/$sid/actions/nope")->assertStatus(422)->assertJsonPath('error.code', 'action_failed');
        $this->as($admin)->postJson("/api/v1/admin/services/$sid/terminate")->assertOk()->assertJsonPath('data.status', 'terminated');
        $this->as($admin)->getJson("/api/v1/admin/services?client_id={$client->id}")->assertJsonCount(2, 'data');
    }

    public function test_webhook_secret_is_shown_once_and_resources_hide_secrets(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $admin = $this->admin();

        $created = $this->as($admin)->postJson('/api/v1/admin/webhooks', ['url' => 'https://hooks.example.com/in', 'events' => ['invoice.paid']])->assertCreated()->assertJsonStructure(['data' => ['id', 'secret']]);
        $this->assertStringStartsWith('whsec_', $created->json('data.secret'));
        $id = $created->json('data.id');
        $this->as($admin)->getJson("/api/v1/admin/webhooks/$id")->assertOk()->assertJsonMissingPath('data.secret');
        $this->as($admin)->getJson('/api/v1/admin/webhooks')->assertJsonMissingPath('data.0.secret');
        $this->as($admin)->postJson('/api/v1/admin/webhooks', ['url' => 'https://x.test', 'events' => ['nope.event']])->assertStatus(422);

        $this->as($admin)->postJson("/api/v1/admin/webhooks/$id/test")->assertStatus(202)->assertJsonPath('data.event', 'ping');
        Http::assertSent(fn ($r) => $r->hasHeader('X-Billing-Signature') && $r->url() === 'https://hooks.example.com/in');
        $this->as($admin)->getJson("/api/v1/admin/webhooks/$id/deliveries")->assertOk()->assertJsonPath('data.0.success', true);
        $this->as($admin)->getJson('/api/v1/admin/events')->assertOk()->assertJsonFragment(['name' => 'invoice.paid']);
        $this->as($admin)->getJson('/api/v1/admin/plugins')->assertOk();
        $this->assertNotNull(WebhookEndpoint::find($id)->secret);
    }

    public function test_domain_events_are_delivered_through_resources(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $client = Client::factory()->create();
        WebhookEndpoint::create(['url' => 'https://hooks.example.com/in', 'secret' => 's', 'events' => ['invoice.created'], 'is_active' => true]);
        $product = Product::factory()->priced(1200)->create();

        app(OrderService::class)->place($client, $product, BillingCycle::Monthly);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['event'] ?? null) === 'invoice.created'
                && $body['data']['total']['amount'] === 1200
                && ! array_key_exists('secret', $body['data']);
        });
    }

    public function test_services_never_expose_secrets_and_servers_are_redacted(): void
    {
        $user = $this->customer();
        ['service' => $service] = $this->order($user);
        $service->rememberSecret(['password' => 'hunter2'])->remember(['username' => 'acct'])->save();

        $json = $this->as($user)->getJson("/api/v1/services/{$service->uuid}")->assertOk()->assertJsonPath('data.provision.username', 'acct')->getContent();
        $this->assertStringNotContainsString('hunter2', $json);
        $server = Server::create(['module' => 'x', 'name' => 'S', 'hostname' => 'h', 'secret' => 'topsecret', 'config' => ['k' => 'v']]);
        $this->assertStringNotContainsString('topsecret', json_encode((new ServerResource($server))->resolve(request())));
    }

    public function test_openapi_matches_the_routes(): void
    {
        $spec = $this->getJson('/api/v1/openapi.json')->assertOk()->assertJsonPath('openapi', '3.1.0')->json();
        $documented = [];
        foreach ($spec['paths'] as $path => $ops) {
            foreach (array_keys($ops) as $method) {
                $documented[] = strtoupper($method).' '.rtrim('/api/v1'.($path === '/' ? '' : $path), '/');
            }
        }
        $actual = [];
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1') || preg_match('#^api/v1/x/#', $route->uri())) {
                continue;
            }
            foreach ($route->methods() as $m) {
                if ($m !== 'HEAD') {
                    $actual[] = $m.' /'.rtrim($route->uri(), '/');
                }
            }
        }
        sort($documented);
        sort($actual);
        $this->assertSame($actual, $documented);
    }
}
