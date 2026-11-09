<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Server;
use App\Plugins\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeServerModule;
use Tests\TestCase;

/** Products (module validation, ordering), product groups, servers and the module catalogue. */
class AdminCatalogTest extends TestCase
{
    use ApiTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRegistry::class)->register(new FakeServerModule);
    }

    public function test_product_groups_crud_and_ordering(): void
    {
        $admin = $this->admin();
        $a = $this->as($admin)->postJson('/api/v1/admin/product-groups', ['name' => 'Hosting', 'description' => 'Web'])->assertCreated()
            ->assertJsonPath('data.slug', 'hosting')->assertJsonPath('data.is_visible', true)->json('data.id');
        $b = $this->as($admin)->postJson('/api/v1/admin/product-groups', ['name' => 'Hosting'])->assertCreated()->assertJsonPath('data.slug', 'hosting-2')->json('data.id');
        $this->as($admin)->postJson('/api/v1/admin/product-groups', [])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

        $this->as($admin)->patchJson("/api/v1/admin/product-groups/$a", ['is_visible' => false])->assertOk()->assertJsonPath('data.is_visible', false)->assertJsonPath('data.name', 'Hosting');
        Product::factory()->priced(100)->create(['product_group_id' => $a]);
        $this->as($admin)->getJson("/api/v1/admin/product-groups/$a")->assertOk()->assertJsonPath('data.products_count', 1);

        $this->as($admin)->postJson('/api/v1/admin/product-groups/reorder', ['ids' => [$b, $a]])->assertOk()->assertJsonPath('data.0.id', $b)->assertJsonPath('data.1.id', $a);
        $this->as($admin)->getJson('/api/v1/admin/product-groups')->assertOk()->assertJsonPath('data.0.id', $b)->assertJsonPath('meta.total', 2);
        $this->as($admin)->postJson('/api/v1/admin/product-groups/reorder', ['ids' => [999]])->assertStatus(422);

        $this->as($this->staffUser())->deleteJson("/api/v1/admin/product-groups/$a")->assertStatus(403);
        $this->as($admin)->deleteJson("/api/v1/admin/product-groups/$a")->assertNoContent();
        $this->assertNull(Product::first()->product_group_id, 'products of a deleted group become ungrouped');
    }

    public function test_products_are_validated_against_their_module_and_can_be_ordered(): void
    {
        $admin = $this->admin();
        $this->as($admin)->postJson('/api/v1/admin/products', ['name' => 'X', 'module' => 'nope'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['module']]]);
        $this->as($admin)->postJson('/api/v1/admin/products', ['name' => 'X', 'module' => 'fakeserver'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['module_config.package']]]);
        $this->as($admin)->postJson('/api/v1/admin/products', ['name' => 'X', 'module' => 'fakeserver', 'module_config' => ['package' => 'a', 'bogus' => 1]])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['module_config.bogus']]]);

        $id = $this->as($admin)->postJson('/api/v1/admin/products', ['name' => 'VPS', 'module' => 'fakeserver', 'module_config' => ['package' => 'small'], 'prices' => [['cycle' => 'monthly', 'currency' => 'usd', 'amount' => 500]]])
            ->assertCreated()->assertJsonPath('data.module', 'fakeserver')->assertJsonPath('data.module_config.package', 'small')->json('data.id');
        // an update that leaves the module alone keeps the stored module config
        $this->as($admin)->patchJson("/api/v1/admin/products/$id", ['name' => 'VPS 2'])->assertOk()->assertJsonPath('data.module_config.package', 'small');
        $this->as($admin)->patchJson("/api/v1/admin/products/$id", ['module' => null])->assertOk()->assertJsonPath('data.module', null)->assertJsonPath('data.module_config', null);
        $this->as($admin)->postJson('/api/v1/admin/products', ['name' => 'Dup', 'slug' => 'vps-2'])->assertCreated();
        $this->as($admin)->postJson('/api/v1/admin/products', ['name' => 'Dup', 'slug' => 'vps-2'])->assertStatus(422);
        $this->as($admin)->postJson('/api/v1/admin/products', ['name' => 'Two prices', 'prices' => [['cycle' => 'monthly', 'currency' => 'USD', 'amount' => 1], ['cycle' => 'monthly', 'currency' => 'usd', 'amount' => 2]]])->assertStatus(422);

        $other = $this->as($admin)->postJson('/api/v1/admin/products', ['name' => 'Other'])->json('data.id');
        $this->as($admin)->postJson('/api/v1/admin/products/reorder', ['ids' => [$other, $id]])->assertOk()->assertJsonPath('data.0.id', $other);
        $this->as($admin)->getJson('/api/v1/admin/products?product_group_id=999')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_product_module_secrets_are_not_returned(): void
    {
        app(ModuleRegistry::class)->register(new class extends FakeServerModule
        {
            public function key(): string
            {
                return 'secretive';
            }

            public function productFields(): array
            {
                return [['key' => 'package', 'label' => 'Package', 'type' => 'text'], ['key' => 'license_key', 'label' => 'Key', 'type' => 'password']];
            }
        });
        $admin = $this->admin();
        $res = $this->as($admin)->postJson('/api/v1/admin/products', ['name' => 'S', 'module' => 'secretive', 'module_config' => ['package' => 'pro', 'license_key' => 'LIC-123']])->assertCreated()
            ->assertJsonPath('data.module_config.package', 'pro')->assertJsonMissingPath('data.module_config.license_key');
        $this->assertStringNotContainsString('LIC-123', $res->getContent());
        $this->assertSame('LIC-123', Product::find($res->json('data.id'))->module_config['license_key']);
        $this->assertStringNotContainsString('LIC-123', $this->as($admin)->getJson('/api/v1/admin/products')->getContent());
        // a partial update keeps the stored secret
        $this->as($admin)->patchJson('/api/v1/admin/products/'.$res->json('data.id'), ['module_config' => ['package' => 'max']])->assertOk()->assertJsonPath('data.module_config.package', 'max');
        $this->assertSame('LIC-123', Product::find($res->json('data.id'))->module_config['license_key']);
    }

    public function test_deleting_a_product_with_live_services_is_refused(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->priced(100)->create();
        $client = Client::factory()->create();
        $service = $this->makeService($client, $product);

        $this->as($this->staffUser())->deleteJson("/api/v1/admin/products/{$product->id}")->assertStatus(403);
        $this->as($admin)->deleteJson("/api/v1/admin/products/{$product->id}")->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $service->update(['status' => 'terminated']);
        $this->as($admin)->deleteJson("/api/v1/admin/products/{$product->id}")->assertNoContent();
    }

    public function test_modules_catalogue_lists_fields(): void
    {
        $res = $this->as($this->staffUser())->getJson('/api/v1/admin/modules')->assertOk()->assertJsonFragment(['key' => 'fakeserver', 'uses_servers' => true]);
        $module = collect($res->json('data'))->firstWhere('key', 'fakeserver');
        $this->assertSame(['reseller', 'token'], array_column($module['server_fields'], 'key'));
        $this->assertTrue($module['server_fields'][1]['secret']);
        $this->assertArrayNotHasKey('value', $module['server_fields'][0]);
    }

    public function test_servers_crud_never_returns_secrets(): void
    {
        $admin = $this->admin();
        $payload = ['module' => 'fakeserver', 'name' => 'WHM 1', 'hostname' => 'whm1.example.com', 'port' => 2087, 'username' => 'root', 'secret' => 'root-api-token',
            'max_services' => 50, 'config' => ['reseller' => 'acme', 'token' => 'config-secret']];
        $res = $this->as($admin)->postJson('/api/v1/admin/servers', $payload)->assertCreated()
            ->assertJsonPath('data.name', 'WHM 1')->assertJsonPath('data.has_secret', true)->assertJsonPath('data.use_ssl', true)->assertJsonPath('data.config.reseller', 'acme')
            ->assertJsonPath('data.config_secrets_set.token', true)->assertJsonMissingPath('data.secret')->assertJsonMissingPath('data.config.token');
        $id = $res->json('data.id');
        $this->assertStringNotContainsString('root-api-token', $res->getContent());
        $this->assertStringNotContainsString('config-secret', $res->getContent());
        $this->assertSame('root-api-token', Server::find($id)->secret);

        $list = $this->as($admin)->getJson('/api/v1/admin/servers')->assertOk()->assertJsonPath('data.0.live_services_count', 0)->getContent();
        $this->assertStringNotContainsString('root-api-token', $list);
        $this->assertStringNotContainsString('config-secret', $this->as($admin)->getJson("/api/v1/admin/servers/$id")->assertOk()->getContent());

        // partial update: blank secret and omitted config keep what is stored; a given config key is merged
        $this->as($admin)->patchJson("/api/v1/admin/servers/$id", ['name' => 'WHM one', 'secret' => '', 'config' => ['reseller' => 'beta']])->assertOk()
            ->assertJsonPath('data.name', 'WHM one')->assertJsonPath('data.config.reseller', 'beta')->assertJsonPath('data.use_ssl', true);
        $server = Server::find($id);
        $this->assertSame('root-api-token', $server->secret);
        $this->assertSame('config-secret', $server->config['token']);
        $this->as($admin)->patchJson("/api/v1/admin/servers/$id", ['secret' => 'rotated', 'use_ssl' => false])->assertOk()->assertJsonPath('data.use_ssl', false);
        $this->assertSame('rotated', Server::find($id)->secret);

        $this->as($admin)->postJson('/api/v1/admin/servers', ['module' => 'nope', 'name' => 'x', 'hostname' => 'h'])->assertStatus(422);
        $this->as($admin)->postJson('/api/v1/admin/servers', ['module' => 'fakeserver', 'name' => 'x', 'hostname' => 'bad host!'])->assertStatus(422);
        $this->as($admin)->postJson('/api/v1/admin/servers', ['module' => 'fakeserver', 'name' => 'x', 'hostname' => 'h', 'config' => ['unknown' => 1]])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['config.unknown']]]);

        $this->as($admin)->getJson('/api/v1/admin/servers?module=other')->assertJsonCount(0, 'data');
    }

    public function test_server_connection_test_and_delete_guard(): void
    {
        $admin = $this->admin();
        $server = Server::create(['module' => 'fakeserver', 'name' => 'S', 'hostname' => 'h']);

        $this->as($admin)->postJson("/api/v1/admin/servers/{$server->id}/test")->assertOk()->assertJsonStructure(['data' => ['success', 'message']]);
        $orphan = Server::create(['module' => 'gone', 'name' => 'G', 'hostname' => 'h']);
        $this->as($admin)->postJson("/api/v1/admin/servers/{$orphan->id}/test")->assertStatus(422)->assertJsonPath('error.code', 'domain_error');

        $product = Product::factory()->priced(1)->create();
        $this->makeService(null, $product, ['server_id' => $server->id]);
        $this->as($this->staffUser())->deleteJson("/api/v1/admin/servers/{$server->id}")->assertStatus(403);
        $this->as($admin)->deleteJson("/api/v1/admin/servers/{$server->id}")->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $this->as($admin)->deleteJson("/api/v1/admin/servers/{$orphan->id}")->assertNoContent();
    }

    public function test_catalogue_endpoints_need_their_abilities_and_staff_tokens(): void
    {
        $server = Server::create(['module' => 'fakeserver', 'name' => 'S', 'hostname' => 'h']);
        $group = ProductGroup::create(['name' => 'G', 'slug' => 'g']);
        $customer = $this->customer();

        foreach (['/servers', "/servers/{$server->id}", '/product-groups', "/product-groups/{$group->id}", '/modules'] as $path) {
            $this->as($customer)->getJson('/api/v1/admin'.$path)->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
            $this->flushHeaders()->getJson('/api/v1/admin'.$path)->assertStatus(401);
        }
        $this->as($customer)->postJson('/api/v1/admin/servers', [])->assertStatus(403);
        $this->as($customer)->postJson('/api/v1/admin/product-groups', ['name' => 'x'])->assertStatus(403);

        $admin = $this->admin();
        $this->as($admin, ['servers.read'])->getJson('/api/v1/admin/servers')->assertOk();
        $this->as($admin, ['servers.read'])->getJson('/api/v1/admin/product-groups')->assertStatus(403);
        $this->as($admin, ['servers.read'])->postJson('/api/v1/admin/servers', [])->assertStatus(403);
        $this->as($admin, ['servers.read'])->postJson("/api/v1/admin/servers/{$server->id}/test")->assertStatus(403);
        $this->as($admin, ['products.read'])->getJson('/api/v1/admin/product-groups')->assertOk();
        $this->as($admin, ['products.read'])->postJson('/api/v1/admin/product-groups', ['name' => 'x'])->assertStatus(403);
        $this->as($admin, ['products.write'])->postJson('/api/v1/admin/product-groups', ['name' => 'x'])->assertCreated();
    }
}
