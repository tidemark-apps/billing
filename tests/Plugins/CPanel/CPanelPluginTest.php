<?php

namespace Tests\Plugins\CPanel;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Plugins\ModuleRegistry;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ProvisioningService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Plugins\CPanel\UsernameGenerator;
use Tests\TestCase;

class CPanelPluginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('cpanel');
    }

    private function whm(array $data = [], string $reason = 'OK'): PromiseInterface
    {
        $body = ['metadata' => ['command' => 'whm', 'reason' => $reason, 'result' => 1, 'version' => 1]];
        if ($data !== []) {
            $body['data'] = $data;
        }

        return Http::response($body);
    }

    private function whmError(string $reason): PromiseInterface
    {
        return Http::response(['metadata' => ['command' => 'whm', 'reason' => $reason, 'result' => 0, 'version' => 1]]);
    }

    private function server(): Server
    {
        return Server::create(['module' => 'cpanel', 'name' => 'WHM 1', 'hostname' => 'srv.example.test', 'username' => 'root', 'secret' => 'TOKEN123', 'config' => ['nameserver1' => 'ns1.example.test'], 'verify_ssl' => false]);
    }

    private function service(string $label = 'example.com', array $provision = [], ?array $secrets = null): Service
    {
        $product = Product::factory()->priced(1000)->module('cpanel', ['package' => 'gold', 'feature_list' => 'default'])->create();

        return Service::create([
            'client_id' => Client::factory()->create(['email' => 'jane@example.test'])->id,
            'product_id' => $product->id,
            'server_id' => $this->server()->id,
            'status' => ServiceStatus::Pending,
            'label' => $label,
            'billing_cycle' => BillingCycle::Monthly,
            'currency' => 'USD',
            'amount' => 1000,
            'provision' => $provision ?: null,
            'secrets' => $secrets,
        ]);
    }

    private function module()
    {
        return app(ModuleRegistry::class)->get('cpanel');
    }

    /** @return array<string, mixed> */
    private function params(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return array_merge($query, $request->data());
    }

    private function sentTo(string $function): Request
    {
        $hits = Http::recorded(fn (Request $r) => str_contains($r->url(), "/json-api/$function"));
        $this->assertNotEmpty($hits, "No request to $function was sent.");

        return $hits->first()[0];
    }

    public function test_module_is_registered(): void
    {
        $this->assertNotNull($this->module());
        $this->assertTrue($this->module()->usesServers());
    }

    public function test_server_test_reports_version_and_packages(): void
    {
        Http::fake(['*/json-api/version*' => $this->whm(['version' => '11.130.0.5']), '*/json-api/listpkgs*' => $this->whm(['pkg' => [['name' => 'gold'], ['name' => 'silver']]])]);

        $result = $this->module()->testServer($this->server());

        $this->assertTrue($result->success);
        $this->assertStringContainsString('11.130.0.5', $result->message);
        $this->assertStringContainsString('2 package', $result->message);
        $this->assertSame('whm root:TOKEN123', $this->sentTo('version')->header('Authorization')[0]);
    }

    public function test_server_test_fails_on_bad_credentials(): void
    {
        Http::fake(['*' => Http::response('denied', 403)]);

        $result = $this->module()->testServer($this->server());

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Authentication failed', $result->message);
        $this->assertStringNotContainsString('TOKEN123', $result->message);
    }

    public function test_create_sends_account_details_and_returns_data_and_secrets(): void
    {
        Http::fake(['*/json-api/createacct*' => $this->whm(['ip' => '192.0.2.1', 'nameserver' => 'ns1.example.test', 'package' => 'gold'])]);
        $service = $this->service('Example.com');

        $result = $this->module()->create($service);

        $this->assertTrue($result->success, $result->message);
        $p = $this->params($this->sentTo('createacct'));
        $this->assertSame('example', $p['username']);
        $this->assertSame('example.com', $p['domain']);
        $this->assertSame('gold', $p['plan']);
        $this->assertSame('jane@example.test', $p['contactemail']);
        $this->assertSame('default', $p['featurelist']);
        $this->assertSame('n', $p['ip']);
        $this->assertSame($result->secrets['password'], $p['password']);
        $this->assertGreaterThanOrEqual(16, strlen($p['password']));
        $this->assertSame(['username' => 'example', 'domain' => 'example.com', 'package' => 'gold', 'server' => 'srv.example.test', 'nameserver1' => 'ns1.example.test'], $result->data);
        // WHM API 1 is documented as GET only: parameters ride in the query string, plus api.version=1
        $this->assertSame('GET', $this->sentTo('createacct')->method());
        $this->assertEquals(1, $p['api_version'] ?? $p['api.version'] ?? null);
    }

    public function test_username_derivation(): void
    {
        $this->assertSame('averyver', UsernameGenerator::make('averyverylongdomainname.com'));
        $this->assertSame('u123shop', UsernameGenerator::make('123shop.com'));
        $this->assertSame('utest', UsernameGenerator::make('test.com'));
        $this->assertSame('uroot', UsernameGenerator::make('root.org'));
        $this->assertSame('mysite', UsernameGenerator::make('my-site.co.uk'));
        $this->assertSame('averyve1', UsernameGenerator::make('averyverylongdomainname.com', 1));
        $this->assertSame('user', UsernameGenerator::make('---.com'));
    }

    public function test_taken_username_is_retried_with_a_suffix(): void
    {
        Http::fake(['*/json-api/createacct*' => Http::sequence()->push(['metadata' => ['result' => 0, 'reason' => 'The username “example” already exists.']])->push(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => []])]);

        $result = $this->module()->create($this->service());

        $this->assertTrue($result->success, $result->message);
        $this->assertSame('example1', $result->data['username']);
        Http::assertSentCount(2);
    }

    public function test_domain_conflicts_are_not_retried(): void
    {
        Http::fake(['*/json-api/createacct*' => $this->whmError('The domain “example.com” already exists on this server.')]);

        $result = $this->module()->create($this->service());

        $this->assertFalse($result->success);
        $this->assertStringContainsString('already exists', $result->message);
        Http::assertSentCount(1);
    }

    public function test_invalid_domain_is_rejected_before_calling_whm(): void
    {
        Http::fake();

        $result = $this->module()->create($this->service('not a domain'));

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    public function test_suspend_unsuspend_terminate_and_change_package_send_the_right_calls(): void
    {
        Http::fake(['*' => $this->whm()]);
        $service = $this->service('example.com', ['username' => 'example']);

        $this->assertTrue($this->module()->suspend($service, 'Overdue invoice')->success);
        $this->assertSame(['user' => 'example', 'reason' => 'Overdue invoice'], collect($this->params($this->sentTo('suspendacct')))->only(['user', 'reason'])->all());

        $this->assertTrue($this->module()->unsuspend($service)->success);
        $this->assertSame('example', $this->params($this->sentTo('unsuspendacct'))['user']);

        $service->product->update(['module_config' => ['package' => 'platinum']]);
        $result = $this->module()->changePackage($service->refresh());
        $this->assertTrue($result->success);
        $this->assertSame(['user' => 'example', 'pkg' => 'platinum'], collect($this->params($this->sentTo('changepackage')))->only(['user', 'pkg'])->all());
        $this->assertSame('platinum', $result->data['package']);

        $this->assertTrue($this->module()->terminate($service)->success);
        $this->assertSame('example', $this->params($this->sentTo('removeacct'))['username']);
    }

    public function test_http_server_error_and_non_json_responses_fail_cleanly(): void
    {
        Http::fake(['*' => Http::response('<html>boom</html>', 500)]);
        $result = $this->module()->testServer($this->server());
        $this->assertFalse($result->success);
        $this->assertStringContainsString('Unexpected response', $result->message);

        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['metadata' => ['result' => 0, 'reason' => 'API failure']], 500)]);
        $this->assertStringContainsString('API failure', $this->module()->testServer($this->server())->message);
    }

    public function test_calls_without_an_account_fail_cleanly(): void
    {
        Http::fake();

        $this->assertFalse($this->module()->suspend($this->service())->success);
        Http::assertNothingSent();
    }

    public function test_api_error_leaves_the_service_pending_with_the_reason(): void
    {
        Http::fake(['*/json-api/createacct*' => $this->whmError('Package “gold” does not exist.')]);
        $service = $this->service();

        app(ProvisioningService::class)->activate($service);

        $service->refresh();
        $this->assertSame(ServiceStatus::Pending, $service->status);
        $this->assertStringContainsString('Package “gold” does not exist.', $service->provision_error);
        $this->assertDatabaseHas('service_logs', ['service_id' => $service->id, 'action' => 'create', 'success' => false]);
    }

    public function test_login_redirects_to_a_single_sign_on_url(): void
    {
        Http::fake(['*/json-api/create_user_session*' => $this->whm(['service' => 'cpaneld', 'session' => 'abc', 'cp_security_token' => '/cpsess123', 'expires' => 1700000000, 'url' => 'https://srv.example.test:2083/cpsess123/login/?session=abc'])]);
        $service = $this->service('example.com', ['username' => 'example']);

        $result = app(ProvisioningService::class)->runAction($service, 'login', 'portal');

        $this->assertSame('https://srv.example.test:2083/cpsess123/login/?session=abc', $result->redirect);
        $p = $this->params($this->sentTo('create_user_session'));
        $this->assertSame(['example', 'cpaneld'], [$p['user'], $p['service']]);

        Http::swap(new Factory);
        Http::fake(['*/json-api/create_user_session*' => $this->whm(['service' => 'webmaild', 'session' => 'def', 'cp_security_token' => '/cpsess1', 'expires' => 1700000000, 'url' => 'https://srv.example.test:2096/cpsess1/login/'])]);
        app(ProvisioningService::class)->runAction($service, 'webmail', 'portal');
        $this->assertSame('webmaild', $this->params(Http::recorded()->last()[0])['service']);
    }

    public function test_reset_password_stores_the_new_password_without_logging_it(): void
    {
        Http::fake(['*/json-api/passwd*' => $this->whm()]);
        $service = $this->service('example.com', ['username' => 'example'], ['password' => 'old']);

        $result = app(ProvisioningService::class)->runAction($service, 'reset_password', 'portal');

        $this->assertTrue($result->success);
        $new = $service->refresh()->secret('password');
        $this->assertNotSame('old', $new);
        $this->assertSame($new, $this->params($this->sentTo('passwd'))['password']);
        $this->assertDatabaseMissing('service_logs', ['message' => $new]);
        $this->assertStringNotContainsString($new, $result->message);
    }

    public function test_paying_the_invoice_provisions_the_account_end_to_end(): void
    {
        Http::fake(['*/json-api/createacct*' => $this->whm(['ip' => '192.0.2.1', 'package' => 'gold'])]);
        $this->server();
        $client = Client::factory()->create(['email' => 'buyer@example.test']);
        $product = Product::factory()->priced(1500)->module('cpanel', ['package' => 'gold'])->create();

        ['service' => $service, 'invoice' => $invoice] = app(OrderService::class)->place($client, $product, BillingCycle::Monthly, ['label' => 'shop.example.org']);
        app(PaymentService::class)->recordManual($invoice, $invoice->total, 'bank transfer');

        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame('shop', $service->data('username'));
        $this->assertNotEmpty($service->secret('password'));
        $this->assertNotNull($service->server_id);
        $this->assertNull($service->provision_error);
    }
}
