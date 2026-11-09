<?php

namespace Tests\Plugins\Enhance;

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
use Tests\TestCase;

class EnhanceModuleTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('enhance');
        $this->server = Server::create(['module' => 'enhance', 'name' => 'Enhance 1', 'hostname' => 'panel.test', 'secret' => 'tok123', 'config' => ['org_id' => 'parent-org'], 'is_active' => true]);
    }

    /** Http::fake() stubs accumulate and the first match wins, so start from a clean client when re-faking. */
    private function resetHttp(): void
    {
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstance(Factory::class);
    }

    private function fakeCreate(): void
    {
        Http::fake([
            'panel.test/api/orgs/parent-org/customers' => Http::response(['id' => 'cust-1'], 201),
            'panel.test/api/logins*' => Http::response(['id' => 'login-1'], 201),
            'panel.test/api/orgs/cust-1/members' => Http::response(['id' => 'member-1'], 201),
            'panel.test/api/orgs/parent-org/customers/cust-1/subscriptions' => Http::response(['id' => 77], 201),
            'panel.test/api/orgs/cust-1/websites' => Http::response(['id' => 'site-1'], 201),
            '*' => Http::response(null, 204),
        ]);
    }

    private function service(array $config = ['plan_id' => 5, 'create_website' => true], ?string $label = 'example.com'): Service
    {
        $product = Product::factory()->priced(1000)->module('enhance', $config)->create();

        return Service::create([
            'client_id' => Client::factory()->create()->id, 'product_id' => $product->id, 'server_id' => $this->server->id,
            'status' => ServiceStatus::Pending, 'label' => $label, 'billing_cycle' => BillingCycle::Monthly, 'currency' => 'USD', 'amount' => 1000,
        ]);
    }

    public function test_create_builds_org_login_member_subscription_and_website(): void
    {
        $this->fakeCreate();
        $service = app(ProvisioningService::class)->activate($this->service());

        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame('cust-1', $service->data('org_id'));
        $this->assertSame('member-1', $service->data('member_id'));
        $this->assertSame(77, $service->data('subscription_id'));
        $this->assertSame('site-1', $service->data('website_id'));
        $this->assertSame('example.com', $service->data('domain'));
        $this->assertNotEmpty($service->secret('password'));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/subscriptions') && $r['planId'] === 5 && $r->hasHeader('Authorization', 'Bearer tok123'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/logins?orgId=cust-1') && $r['password'] === $service->secret('password'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/members') && $r['roles'] === ['Owner']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/websites') && $r['domain'] === 'example.com' && $r['subscriptionId'] === 77);
    }

    public function test_skips_website_for_non_domain_label(): void
    {
        $this->fakeCreate();
        $service = app(ProvisioningService::class)->activate($this->service(label: 'my site'));

        $this->assertNull($service->data('website_id'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/websites'));
    }

    public function test_failure_rolls_back_org_and_keeps_service_pending(): void
    {
        Http::fake([
            'panel.test/api/orgs/parent-org/customers' => Http::response(['id' => 'cust-1'], 201),
            'panel.test/api/logins*' => Http::response(['code' => 'login_exists', 'message' => 'email already exists'], 409),
            '*' => Http::response(null, 204),
        ]);
        $service = app(ProvisioningService::class)->activate($this->service());

        $this->assertSame(ServiceStatus::Pending, $service->status);
        $this->assertStringContainsString('login_exists - email already exists', $service->provision_error);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/api/orgs/cust-1') && str_contains($r->url(), 'force=true'));
    }

    public function test_missing_plan_id_fails_cleanly(): void
    {
        Http::fake();
        $service = app(ProvisioningService::class)->activate($this->service(['create_website' => false]));

        $this->assertSame(ServiceStatus::Pending, $service->status);
        $this->assertStringContainsString('plan', $service->provision_error);
        Http::assertNothingSent();
    }

    public function test_suspend_unsuspend_change_package_and_terminate(): void
    {
        $this->fakeCreate();
        $provisioning = app(ProvisioningService::class);
        $service = $provisioning->activate($this->service());

        $provisioning->suspend($service);
        $this->assertSame(ServiceStatus::Suspended, $service->refresh()->status);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/orgs/cust-1/subscriptions/77') && $r['isSuspended'] === true);

        $provisioning->unsuspend($service);
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && ($r['isSuspended'] ?? null) === false);

        $service->product->update(['module_config' => ['plan_id' => 9]]);
        $provisioning->changePackage($service->refresh());
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && ($r['planId'] ?? null) === 9);

        $provisioning->terminate($service->refresh());
        $this->assertSame(ServiceStatus::Terminated, $service->refresh()->status);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/api/orgs/cust-1'));
    }

    public function test_terminate_falls_back_to_soft_delete_when_force_is_forbidden(): void
    {
        $this->fakeCreate();
        $service = app(ProvisioningService::class)->activate($this->service());
        $this->resetHttp();
        Http::fake([
            'panel.test/api/orgs/cust-1?force=true' => Http::response(['code' => 'forbidden', 'message' => 'Insufficient privileges'], 403),
            '*' => Http::response(null, 204),
        ]);

        app(ProvisioningService::class)->terminate($service->refresh());

        $this->assertSame(ServiceStatus::Terminated, $service->refresh()->status);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://panel.test/api/orgs/cust-1');
    }

    public function test_suspend_api_error_keeps_service_active_and_records_error(): void
    {
        $this->fakeCreate();
        $service = app(ProvisioningService::class)->activate($this->service());
        $this->resetHttp();
        Http::fake(['*' => Http::response(['code' => 'internal', 'message' => 'nope'], 500)]);

        app(ProvisioningService::class)->suspend($service);

        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertStringContainsString('HTTP 500', $service->provision_error);
    }

    public function test_login_action_returns_redirect(): void
    {
        $this->fakeCreate();
        $service = app(ProvisioningService::class)->activate($this->service());
        $this->resetHttp();
        Http::fake(['panel.test/api/orgs/cust-1/members/member-1/sso' => Http::response(json_encode('https://panel.test/sso/abc'), 201, ['Content-Type' => 'application/json'])]);

        $result = app(ProvisioningService::class)->runAction($service, 'login', 'portal');

        $this->assertTrue($result->success);
        $this->assertSame('https://panel.test/sso/abc', $result->redirect);
        $this->assertFalse(app(ProvisioningService::class)->runAction($service, 'nonsense', 'portal')->success);
    }

    public function test_server_test_lists_plans_and_reports_errors(): void
    {
        $module = app(ModuleRegistry::class)->get('enhance');
        $plan = fn (int $id, string $name) => ['id' => $id, 'name' => $name, 'orgId' => 'parent-org', 'resources' => [], 'allowances' => [], 'selections' => [], 'subscriptionsCount' => 0, 'createdAt' => '2025-01-01T00:00:00Z', 'planType' => 'shared', 'allowedPhpVersions' => ['php83'], 'defaultPhpVersion' => 'php83', 'redisAllowed' => false];
        Http::fake(['panel.test/api/orgs/parent-org/plans*' => Http::sequence()
            ->push(['items' => [$plan(3, 'Starter')], 'total' => 2])
            ->push(['items' => [$plan(4, 'Pro')], 'total' => 2])]);
        $ok = $module->testServer($this->server);
        $this->assertTrue($ok->success);
        $this->assertStringContainsString('#3 Starter, #4 Pro', $ok->message);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), 'offset=1') && str_contains($r->url(), 'limit=100'));

        $this->resetHttp();
        Http::fake(['*' => Http::response(['code' => 'unauthorized', 'message' => 'Invalid session'], 401)]);
        $this->assertFalse($module->testServer($this->server)->success);
    }

    public function test_paid_order_provisions_via_billing_flow(): void
    {
        $this->fakeCreate();
        $product = Product::factory()->priced(1000)->module('enhance', ['plan_id' => 5])->create();
        ['service' => $service, 'invoice' => $invoice] = app(OrderService::class)->place(Client::factory()->create(), $product, BillingCycle::Monthly, ['label' => 'shop.example.org']);
        app(PaymentService::class)->record($invoice, 'test', $invoice->total, 'x1');

        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame($this->server->id, $service->server_id);
        $this->assertSame('cust-1', $service->data('org_id'));
    }
}
