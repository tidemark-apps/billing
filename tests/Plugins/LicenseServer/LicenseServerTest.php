<?php

namespace Tests\Plugins\LicenseServer;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\LicenseServer\KeyGenerator;
use Plugins\LicenseServer\Models\Activation;
use Plugins\LicenseServer\Models\License;
use Plugins\LicenseServer\Signer;
use Tests\TestCase;

class LicenseServerTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/x/license-server/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePlugin('license-server');
    }

    /** Order and pay for a licensed product; returns the issued licence. */
    private function issue(array $config = [], ?Client $client = null): License
    {
        $config += ['product_code' => 'acme-pro', 'key_prefix' => 'ACME', 'max_activations' => 2, 'binding' => 'domain'];
        $product = Product::factory()->priced(2000, BillingCycle::Annually)->module('license', $config)->create();
        ['invoice' => $invoice, 'service' => $service] = app(OrderService::class)->place($client ?? Client::factory()->create(), $product, BillingCycle::Annually);
        app(PaymentService::class)->record($invoice, 'test', $invoice->total, 'pay-'.$service->id);

        return License::where('service_id', $service->id)->firstOrFail();
    }

    private function lic(string $action, License $l, array $extra = [], array $server = [])
    {
        return $this->postJson(self::BASE.$action, ['key' => $l->key, 'product' => $l->product_code] + $extra, $server);
    }

    public function test_key_format_checksum_and_uniqueness(): void
    {
        $keys = collect(range(1, 50))->map(fn () => KeyGenerator::generate('acme'));
        $this->assertCount(50, $keys->unique());
        $keys->each(fn ($k) => $this->assertMatchesRegularExpression('/^ACME(-[A-HJ-NP-Z2-9]{4}){4}$/', $k));
        $keys->each(fn ($k) => $this->assertTrue(KeyGenerator::isWellFormed($k)));
        $bad = $keys[0];
        $bad[6] = $bad[6] === 'A' ? 'B' : 'A';
        $this->assertFalse(KeyGenerator::isWellFormed($bad));
        $this->assertSame('LIC', KeyGenerator::cleanPrefix('!!'));
    }

    public function test_paid_order_issues_a_licence(): void
    {
        $license = $this->issue();
        $service = Service::find($license->service_id);

        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame($license->key, $service->data('license_key'));
        $this->assertSame('active', $license->status);
        $this->assertSame(2, $license->max_activations);
        $this->assertSame('acme-pro', $license->product_code);
        $this->assertStringStartsWith('ACME-', $license->key);
    }

    public function test_activate_and_verify_return_a_verifiable_signature(): void
    {
        $license = $this->issue();
        $this->lic('activate', $license, ['identifier' => 'https://WWW.Example.com:8443/app'])->assertOk()->assertJson(['valid' => true, 'status' => 'active', 'activations' => ['used' => 1, 'max' => 2]]);

        $res = $this->lic('verify', $license, ['identifier' => 'example.com'])->assertOk();
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $body = $res->json();

        $pub = $this->getJson(self::BASE.'public-key')->assertOk()->json('public_key');
        $this->assertSame(Signer::publicKey(), $pub);
        $payload = Signer::payload($license->key, $license->product_code, $body['status'], $body['expires_at'], 'example.com', $body['issued_at'], true);
        $this->assertTrue(sodium_crypto_sign_verify_detached(base64_decode($body['signature']), $payload, base64_decode($pub)));
        $tampered = Signer::payload($license->key, $license->product_code, 'active', '2099-01-01T00:00:00+00:00', 'example.com', $body['issued_at'], true);
        $this->assertFalse(sodium_crypto_sign_verify_detached(base64_decode($body['signature']), $tampered, base64_decode($pub)));
    }

    public function test_unknown_malformed_and_wrong_product_are_all_just_invalid(): void
    {
        $license = $this->issue();
        $responses = [
            $this->postJson(self::BASE.'verify', ['key' => 'ACME-AAAA-AAAA-AAAA-AAAA', 'product' => 'acme-pro', 'identifier' => 'a.com']),
            $this->postJson(self::BASE.'verify', ['key' => 'garbage', 'product' => 'acme-pro', 'identifier' => 'a.com']),
            $this->postJson(self::BASE.'verify', ['key' => $license->key, 'product' => 'other', 'identifier' => 'a.com']),
        ];
        foreach ($responses as $r) {
            $r->assertStatus(403)->assertExactJson(['valid' => false, 'error' => ['code' => 'invalid', 'message' => 'Invalid licence.']]);
        }
        $this->postJson(self::BASE.'verify', [])->assertStatus(422);
    }

    public function test_activation_limit_idempotency_and_deactivate(): void
    {
        $license = $this->issue(['max_activations' => 1]);

        $this->lic('activate', $license, ['identifier' => 'one.com'])->assertOk();
        $this->lic('activate', $license, ['identifier' => 'ONE.com'])->assertOk()->assertJson(['activations' => ['used' => 1, 'max' => 1]]);
        $this->lic('activate', $license, ['identifier' => 'two.com'])->assertStatus(409)->assertJsonPath('error.code', 'activation_limit');
        $this->lic('verify', $license, ['identifier' => 'two.com'])->assertStatus(403)->assertJsonPath('error.code', 'not_activated');

        $this->lic('deactivate', $license, ['identifier' => 'one.com'])->assertOk()->assertJson(['deactivated' => true, 'activations' => ['used' => 0, 'max' => 1]]);
        $this->lic('activate', $license, ['identifier' => 'two.com'])->assertOk();
        $this->assertSame(1, Activation::count());
    }

    public function test_identifier_required_for_domain_and_instance_but_not_for_none_or_ip(): void
    {
        $domain = $this->issue();
        $this->lic('activate', $domain)->assertStatus(422)->assertJsonPath('error.code', 'identifier_required');

        $ip = $this->issue(['binding' => 'ip']);
        $this->lic('activate', $ip, [], ['REMOTE_ADDR' => '203.0.113.9'])->assertOk();
        $this->assertSame('203.0.113.9', $ip->activations()->first()->identifier);
        $this->lic('verify', $ip, [], ['REMOTE_ADDR' => '203.0.113.9'])->assertOk();
        $this->lic('verify', $ip, [], ['REMOTE_ADDR' => '203.0.113.10'])->assertStatus(403);
        $this->lic('activate', $ip, ['identifier' => 'not-an-ip'])->assertStatus(422);

        $none = $this->issue(['binding' => 'none']);
        $this->lic('verify', $none)->assertOk()->assertJson(['valid' => true]);
        $this->lic('activate', $none)->assertOk();
        $this->assertSame(0, $none->activations()->count());

        $instance = $this->issue(['binding' => 'instance']);
        $this->lic('activate', $instance, ['identifier' => 'Instance-ABC'])->assertOk();
        $this->assertSame('Instance-ABC', $instance->activations()->first()->identifier);
    }

    public function test_suspend_unsuspend_terminate_propagate_and_block_verification(): void
    {
        $license = $this->issue();
        $service = Service::find($license->service_id);
        $provisioning = app(ProvisioningService::class);
        $this->lic('activate', $license, ['identifier' => 'a.com'])->assertOk();

        $provisioning->suspend($service, 'test');
        $this->assertSame('suspended', $license->refresh()->status);
        $this->lic('verify', $license, ['identifier' => 'a.com'])->assertStatus(403)->assertJsonPath('error.code', 'suspended');
        $this->lic('activate', $license, ['identifier' => 'b.com'])->assertStatus(403);

        $provisioning->unsuspend($service->refresh());
        $this->assertSame('active', $license->refresh()->status);
        $this->lic('verify', $license, ['identifier' => 'a.com'])->assertOk();

        $provisioning->terminate($service->refresh());
        $this->assertSame('revoked', $license->refresh()->status);
        $this->lic('verify', $license, ['identifier' => 'a.com'])->assertStatus(403)->assertJsonPath('error.code', 'revoked');
        $this->assertTrue(ActivityLog::where('action', 'license.revoked')->exists(), 'revocation is recorded via the plugin event listener');
    }

    public function test_grace_days_keep_a_suspended_licence_valid_for_a_while(): void
    {
        $license = $this->issue(['grace_days' => 3]);
        $service = Service::find($license->service_id);
        $this->lic('activate', $license, ['identifier' => 'a.com'])->assertOk();
        app(ProvisioningService::class)->suspend($service);

        $this->lic('verify', $license, ['identifier' => 'a.com'])->assertOk()->assertJsonPath('status', 'grace');
        $this->travel(4)->days();
        $this->lic('verify', $license, ['identifier' => 'a.com'])->assertStatus(403)->assertJsonPath('error.code', 'suspended');
    }

    public function test_expiry_date_makes_licence_expired(): void
    {
        $license = $this->issue();
        $license->update(['expires_at' => now()->subDay()]);

        $this->lic('activate', $license, ['identifier' => 'a.com'])->assertStatus(403)->assertJsonPath('error.code', 'expired');
    }

    public function test_change_package_syncs_limits(): void
    {
        $license = $this->issue();
        $service = Service::find($license->service_id);
        $service->product->update(['module_config' => ['product_code' => 'acme-pro', 'max_activations' => 10, 'binding' => 'instance']]);

        app(ProvisioningService::class)->changePackage($service->refresh());

        $this->assertSame(10, $license->refresh()->max_activations);
        $this->assertSame('instance', $license->binding);
    }

    public function test_admin_pages_require_staff(): void
    {
        $license = $this->issue();
        $url = route('admin.license-server.index');

        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($this->customer())->get($url)->assertForbidden();
        $this->actingAs($this->customer())->get(route('admin.license-server.show', $license))->assertForbidden();
        $this->actingAs($this->customer())->post(route('admin.license-server.revoke', $license))->assertForbidden();

        $admin = $this->admin();
        $this->actingAs($admin)->get($url)->assertOk()->assertSee($license->key)->assertSee(Signer::publicKey());
        $this->actingAs($admin)->get($url.'?q='.substr($license->key, 0, 9))->assertOk()->assertSee($license->key);
        $this->actingAs($admin)->get($url.'?q=zzzz-nothing')->assertOk()->assertDontSee($license->key);
        $this->actingAs($admin)->get(route('admin.license-server.show', $license))->assertOk()->assertSee($license->key);
    }

    public function test_admin_can_revoke_reactivate_update_and_remove_activation(): void
    {
        $license = $this->issue();
        $this->lic('activate', $license, ['identifier' => 'a.com'])->assertOk();
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.license-server.update', $license), ['max_activations' => 5, 'extend_days' => 30])->assertRedirect();
        $license->refresh();
        $this->assertSame(5, $license->max_activations);
        $this->assertTrue($license->expires_at->isFuture());

        $this->actingAs($admin)->post(route('admin.license-server.revoke', $license))->assertRedirect();
        $this->assertSame('revoked', $license->refresh()->status);
        $this->actingAs($admin)->post(route('admin.license-server.reactivate', $license))->assertRedirect();
        $this->assertSame('active', $license->refresh()->status);

        $activation = $license->activations()->first();
        $other = $this->issue();
        $this->actingAs($admin)->delete(route('admin.license-server.activations.destroy', [$other, $activation]))->assertNotFound();
        $this->actingAs($admin)->delete(route('admin.license-server.activations.destroy', [$license, $activation]))->assertRedirect();
        $this->assertSame(0, $license->activations()->count());
    }

    public function test_portal_customers_can_only_remove_their_own_activations(): void
    {
        $alice = $this->customer();
        $bob = $this->customer();
        $aliceLicense = $this->issue(client: $alice->client);
        $bobLicense = $this->issue(client: $bob->client);
        $this->lic('activate', $aliceLicense, ['identifier' => 'alice.com'])->assertOk();
        $this->lic('activate', $bobLicense, ['identifier' => 'bob.com'])->assertOk();
        $bobActivation = $bobLicense->activations()->first();
        $aliceActivation = $aliceLicense->activations()->first();

        $this->actingAs($alice)->delete(route('portal.license-server.activations.destroy', $bobActivation->id))->assertNotFound();
        $this->assertSame(1, $bobLicense->activations()->count());

        $this->actingAs($alice)->delete(route('portal.license-server.activations.destroy', $aliceActivation->id))->assertRedirect();
        $this->assertSame(0, $aliceLicense->activations()->count());

        auth()->logout();
        $this->delete(route('portal.license-server.activations.destroy', $bobActivation->id))->assertRedirect(route('login'));
    }

    public function test_service_panels_show_only_the_services_own_licence(): void
    {
        $license = $this->issue();
        $other = $this->issue();
        $service = Service::with('product')->find($license->service_id);

        $portal = (string) extensions('portal.service', ['service' => $service]);
        $this->assertStringContainsString($license->key, $portal);
        $this->assertStringNotContainsString($other->key, $portal);
        $this->assertStringContainsString($license->key, (string) extensions('admin.service', ['service' => $service]));

        $nonLicense = Service::create(['client_id' => $service->client_id, 'product_id' => Product::factory()->priced(100)->create()->id, 'status' => ServiceStatus::Active, 'billing_cycle' => BillingCycle::Monthly, 'currency' => 'USD', 'amount' => 100]);
        $this->assertSame('', trim((string) extensions('portal.service', ['service' => $nonLicense->load('product')])));
    }

    public function test_api_is_rate_limited_per_key(): void
    {
        $license = $this->issue();
        for ($i = 0; $i < 30; $i++) {
            $this->lic('verify', $license, ['identifier' => 'a.com'])->assertStatus(403);
        }
        $this->lic('verify', $license, ['identifier' => 'a.com'])->assertStatus(429);
    }
}
