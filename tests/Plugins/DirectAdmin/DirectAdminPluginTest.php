<?php

namespace Tests\Plugins\DirectAdmin;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Plugins\DirectAdmin\UsernameGenerator;
use Tests\TestCase;

class DirectAdminPluginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('directadmin');
    }

    private function server(array $config = ['ip' => '192.0.2.10', 'nameserver1' => 'ns1.example.test']): Server
    {
        return Server::create(['module' => 'directadmin', 'name' => 'DA 1', 'hostname' => 'da.example.test', 'username' => 'reseller', 'secret' => 'LOGINKEY', 'config' => $config, 'verify_ssl' => false]);
    }

    private function service(string $label = 'example.com', array $provision = [], ?array $secrets = null, array $productConfig = ['package' => 'starter']): Service
    {
        $product = Product::factory()->priced(1000)->module('directadmin', $productConfig)->create();

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

    /** Replace all HTTP fakes and the request log (a second Http::fake() would not override the first). */
    private function refake(array|\Closure $stubs): void
    {
        Http::swap(new Factory);
        Http::fake($stubs);
    }

    private function module()
    {
        return app(ModuleRegistry::class)->get('directadmin');
    }

    /** @return array<string, mixed> */
    private function params(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return array_merge($query, $request->data());
    }

    private function sentTo(string $command, int $nth = 0): Request
    {
        $hits = Http::recorded(fn (Request $r) => str_contains($r->url(), "/$command"))->values();
        $this->assertNotEmpty($hits, "No request to $command was sent.");

        return $hits[$nth][0];
    }

    public function test_module_is_registered(): void
    {
        $this->assertTrue($this->module()->usesServers());
        $this->assertSame('directadmin', $this->module()->key());
    }

    public function test_server_test_ok_and_failure(): void
    {
        Http::fake(['*CMD_API_PACKAGES_USER*' => Http::response('list[]=starter&list[]=pro'), '*CMD_API_SHOW_RESELLER_IPS*' => Http::response('list[]=192.0.2.10')]);
        $result = $this->module()->testServer($this->server());
        $this->assertTrue($result->success);
        $this->assertStringContainsString('2 user package', $result->message);
        $this->assertSame('Basic '.base64_encode('reseller:LOGINKEY'), $this->sentTo('CMD_API_PACKAGES_USER')->header('Authorization')[0]);

        $this->refake(['*' => Http::response('Unauthorized', 401)]);
        $bad = $this->module()->testServer($this->server());
        $this->assertFalse($bad->success);
        $this->assertStringContainsString('Authentication failed', $bad->message);

        $this->refake(['*' => Http::response('<html>Login</html>', 200)]);
        $this->assertStringContainsString('HTML', $this->module()->testServer($this->server())->message);
    }

    public function test_create_sends_account_details(): void
    {
        Http::fake(['*CMD_API_ACCOUNT_USER*' => Http::response('error=0&text=User+Created&details=')]);

        $result = $this->module()->create($this->service('Example.com'));

        $this->assertTrue($result->success, $result->message);
        $p = $this->params($this->sentTo('CMD_API_ACCOUNT_USER'));
        $this->assertSame(['create', 'Submit', 'example', 'jane@example.test', 'example.com', 'starter', '192.0.2.10', 'no'], [$p['action'], $p['add'], $p['username'], $p['email'], $p['domain'], $p['package'], $p['ip'], $p['notify']]);
        $this->assertSame($p['passwd'], $p['passwd2']);
        $this->assertSame($result->secrets['password'], $p['passwd']);
        $this->assertSame(['username' => 'example', 'domain' => 'example.com', 'package' => 'starter', 'server' => 'da.example.test', 'ip' => '192.0.2.10', 'nameserver1' => 'ns1.example.test'], $result->data);
    }

    public function test_username_derivation(): void
    {
        $this->assertSame('averyveryl', UsernameGenerator::make('averyverylongdomainname.com'));
        $this->assertSame('averyver', UsernameGenerator::make('averyverylongdomainname.com', 0, 8));
        $this->assertSame('u123shop', UsernameGenerator::make('123shop.com'));
        $this->assertSame('rootx', UsernameGenerator::make('root.com'));
        $this->assertSame('ab00', UsernameGenerator::make('ab.com'));
        $this->assertSame('averyvery1', UsernameGenerator::make('averyverylongdomainname.com', 1));
    }

    public function test_taken_username_is_retried_with_a_suffix(): void
    {
        Http::fake(['*CMD_API_ACCOUNT_USER*' => Http::sequence()->push('error=1&text=Unable+to+create+user&details=Username+example+already+exists')->push('error=0&text=User+Created')]);

        $result = $this->module()->create($this->service());

        $this->assertTrue($result->success, $result->message);
        $this->assertSame('example1', $result->data['username']);
    }

    public function test_dedicated_ip_mode_picks_a_free_ip(): void
    {
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'CMD_API_SHOW_RESELLER_IPS')) {
                $ip = $this->params($r)['ip'] ?? null;

                return Http::response($ip === null ? 'list[]=192.0.2.10&list[]=192.0.2.11' : ($ip === '192.0.2.11' ? 'status=free&value=0' : 'status=server&value=4'));
            }

            return Http::response('error=0&text=User+Created');
        });

        $result = $this->module()->create($this->service('example.com', [], null, ['package' => 'starter', 'ip_mode' => 'dedicated']));

        $this->assertTrue($result->success, $result->message);
        $this->assertSame('192.0.2.11', $this->params($this->sentTo('CMD_API_ACCOUNT_USER'))['ip']);
    }

    public function test_invalid_domain_is_rejected_before_calling_directadmin(): void
    {
        Http::fake();

        $this->assertFalse($this->module()->create($this->service('nope'))->success);
        Http::assertNothingSent();
    }

    public function test_suspend_uses_the_non_toggling_commands_and_accepts_the_redirect_reply(): void
    {
        // On success DirectAdmin answers a 302 redirect to `location`; it must not be followed or parsed as HTML.
        Http::fake(['*CMD_API_SELECT_USERS*' => Http::response('', 302, ['Location' => '/CMD_SELECT_USERS'])]);
        $service = $this->service('example.com', ['username' => 'example']);

        $this->assertTrue($this->module()->suspend($service, 'Overdue')->success);
        $p = $this->params($this->sentTo('CMD_API_SELECT_USERS'));
        $this->assertSame(['CMD_SELECT_USERS', 'example'], [$p['location'], $p['select0']]);
        $this->assertArrayHasKey('dosuspend', $p);
        $this->assertArrayNotHasKey('suspend', $p);
        Http::assertSentCount(1);

        $this->refake(['*CMD_API_SELECT_USERS*' => Http::response('', 302, ['Location' => '/CMD_SELECT_USERS'])]);
        $this->assertTrue($this->module()->unsuspend($service)->success);
        $p = $this->params($this->sentTo('CMD_API_SELECT_USERS'));
        $this->assertArrayHasKey('dounsuspend', $p);
        $this->assertArrayNotHasKey('dosuspend', $p);
    }

    public function test_terminate_and_change_package(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => '/CMD_USER_SHOW'])]);
        $service = $this->service('example.com', ['username' => 'example']);

        $this->assertTrue($this->module()->terminate($service)->success);
        $p = $this->params($this->sentTo('CMD_API_SELECT_USERS'));
        $this->assertSame(['Confirm', 'yes', 'example'], [$p['confirmed'], $p['delete'], $p['select0']]);

        $service->product->update(['module_config' => ['package' => 'pro']]);
        $result = $this->module()->changePackage($service->refresh());
        $this->assertSame('pro', $result->data['package']);
        $p = $this->params($this->sentTo('CMD_API_MODIFY_USER'));
        $this->assertSame(['package', 'example', 'pro'], [$p['action'], $p['user'], $p['package']]);
    }

    public function test_api_error_leaves_the_service_pending_with_the_reason(): void
    {
        Http::fake(['*CMD_API_ACCOUNT_USER*' => Http::response('error=1&text=Cannot+create&details=Package+starter+does+not+exist%3Cbr%3E')]);
        $service = $this->service();

        app(ProvisioningService::class)->activate($service);

        $service->refresh();
        $this->assertSame(ServiceStatus::Pending, $service->status);
        $this->assertStringContainsString('Cannot create: Package starter does not exist', $service->provision_error);
    }

    public function test_login_creates_a_one_time_url_as_the_impersonated_user_or_falls_back_to_the_panel(): void
    {
        Http::fake(['*CMD_API_LOGIN_KEYS*' => Http::response('error=0&text=One-Time+Login+URL+Created&details=https%3A%2F%2Fda.example.test%3A2222%2FCMD_LOGIN_URL%3Fhash%3DABC')]);
        $service = $this->service('example.com', ['username' => 'example']);
        $service->server->update(['secret' => 'resellerpw']);

        $result = app(ProvisioningService::class)->runAction($service->refresh(), 'login', 'portal');
        $this->assertSame('https://da.example.test:2222/CMD_LOGIN_URL?hash=ABC', $result->redirect);
        $request = $this->sentTo('CMD_API_LOGIN_KEYS');
        $this->assertSame('Basic '.base64_encode('reseller|example:resellerpw'), $request->header('Authorization')[0]);
        $p = $this->params($request);
        $this->assertSame(['create', 'one_time_url', 'resellerpw'], [$p['action'], $p['type'], $p['passwd']]);

        $this->refake(['*CMD_API_LOGIN_KEYS*' => Http::response('error=1&text=Invalid+password&details=')]);
        $fallback = app(ProvisioningService::class)->runAction($service->refresh(), 'login', 'portal');
        $this->assertSame('https://da.example.test:2222/', $fallback->redirect);
    }

    public function test_reset_password_as_the_account_when_its_password_is_stored(): void
    {
        Http::fake(['*CMD_API_USER_PASSWD*' => Http::response('error=0&text=Password+Changed')]);
        $service = $this->service('example.com', ['username' => 'example'], ['password' => 'old-pw']);

        $result = app(ProvisioningService::class)->runAction($service, 'reset_password', 'admin');

        $this->assertTrue($result->success, $result->message);
        $new = $service->refresh()->secret('password');
        $request = $this->sentTo('CMD_API_USER_PASSWD');
        $this->assertSame('Basic '.base64_encode('example:old-pw'), $request->header('Authorization')[0]);
        $p = $this->params($request);
        $this->assertSame(['example', 'old-pw', $new, $new], [$p['username'], $p['oldpassword'], $p['passwd'], $p['passwd2']]);
        $this->assertStringNotContainsString($new, $result->message);
    }

    public function test_reset_password_as_the_reseller_when_no_password_is_stored(): void
    {
        Http::fake(['*CMD_API_USER_PASSWD*' => Http::response('error=0&text=Password+Changed')]);
        $service = $this->service('example.com', ['username' => 'example']);

        $result = app(ProvisioningService::class)->runAction($service, 'reset_password', 'admin');

        $this->assertTrue($result->success, $result->message);
        $request = $this->sentTo('CMD_API_USER_PASSWD');
        $this->assertSame('Basic '.base64_encode('reseller:LOGINKEY'), $request->header('Authorization')[0]);
        $p = $this->params($request);
        $this->assertSame('example', $p['username']);
        $this->assertArrayNotHasKey('oldpassword', $p);
        $this->assertSame($service->refresh()->secret('password'), $p['passwd']);
    }

    public function test_paying_the_invoice_provisions_the_account_end_to_end(): void
    {
        Http::fake(['*CMD_API_ACCOUNT_USER*' => Http::response('error=0&text=User+Created')]);
        $this->server();
        $client = Client::factory()->create(['email' => 'buyer@example.test']);
        $product = Product::factory()->priced(1500)->module('directadmin', ['package' => 'starter'])->create();

        ['service' => $service, 'invoice' => $invoice] = app(OrderService::class)->place($client, $product, BillingCycle::Monthly, ['label' => 'shop.example.org']);
        app(PaymentService::class)->recordManual($invoice, $invoice->total, 'bank transfer');

        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame('shop', $service->data('username'));
        $this->assertSame('192.0.2.10', $service->data('ip'));
        $this->assertNotEmpty($service->secret('password'));
    }
}
