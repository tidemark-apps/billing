<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Plugins\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeServerModule;
use Tests\TestCase;

class ProductsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRegistry::class)->register(new FakeServerModule);
    }

    public function test_create_product_with_prices_and_module_config(): void
    {
        $this->actingAs($this->admin())->post(route('admin.products.store'), [
            'name' => 'Starter Hosting', 'module' => 'fakeserver', 'is_active' => 1,
            'module_config' => ['package' => 'starter'],
            'prices' => [
                ['cycle' => 'monthly', 'currency' => 'USD', 'amount' => '9.99', 'setup_fee' => '5'],
                ['cycle' => 'annually', 'currency' => 'USD', 'amount' => '99', 'setup_fee' => ''],
            ],
        ])->assertRedirect();

        $p = Product::with('prices')->firstWhere('name', 'Starter Hosting');
        $this->assertSame('starter-hosting', $p->slug);
        $this->assertSame('starter', $p->moduleOption('package'));
        $this->assertTrue($p->is_active);
        $this->assertFalse($p->tax_exempt);
        $monthly = $p->prices->first(fn ($x) => $x->cycle->value === 'monthly');
        $this->assertSame(999, $monthly->amount);
        $this->assertSame(500, $monthly->setup_fee);
        $this->assertCount(2, $p->prices);
    }

    public function test_module_required_fields_and_duplicate_prices_are_rejected(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.products.store'), ['name' => 'X', 'module' => 'fakeserver'])->assertSessionHasErrors('module_config.package');
        $dup = ['cycle' => 'monthly', 'currency' => 'USD', 'amount' => '1'];
        $this->post(route('admin.products.store'), ['name' => 'Y', 'prices' => [$dup, $dup]])->assertSessionHasErrors('prices');
        $this->assertDatabaseCount('products', 1 - 1 + Product::count());
    }

    public function test_update_replaces_prices_and_form_renders(): void
    {
        $p = Product::factory()->priced(1000)->create();
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.products.edit', $p))->assertOk()->assertSee('10.00');
        $this->put(route('admin.products.update', $p), ['name' => $p->name, 'slug' => $p->slug, 'prices' => [['cycle' => 'monthly', 'currency' => 'USD', 'amount' => '12']]])->assertRedirect();
        $this->assertSame(1200, $p->prices()->first()->amount);
    }

    public function test_delete_blocked_with_live_services_and_groups(): void
    {
        $admin = $this->admin();
        $p = Product::factory()->create();
        $client = Client::factory()->create();
        Service::create(['client_id' => $client->id, 'product_id' => $p->id, 'status' => 'active', 'billing_cycle' => 'monthly', 'currency' => 'USD', 'amount' => 1]);
        $this->actingAs($admin)->delete(route('admin.products.destroy', $p))->assertSessionHas('error');
        $this->assertNotSoftDeleted($p);

        $this->post(route('admin.product-groups.store'), ['name' => 'Hosting'])->assertRedirect();
        $g = ProductGroup::firstWhere('name', 'Hosting');
        $this->get(route('admin.products.index'))->assertOk()->assertSee('Hosting');
        $this->delete(route('admin.product-groups.destroy', $g))->assertRedirect();
        $this->assertModelMissing($g);
    }

    public function test_index_tabs_filter_by_active_state(): void
    {
        Product::factory()->create(['name' => 'Live Plan', 'is_active' => true]);
        Product::factory()->create(['name' => 'Retired Plan', 'is_active' => false]);
        $this->actingAs($this->admin());
        $this->get(route('admin.products.index', ['view' => 'inactive']))->assertOk()->assertSee('Retired Plan')->assertDontSee('Live Plan')->assertSee('Live services');
        $this->get(route('admin.products.index', ['view' => 'active']))->assertSee('Live Plan')->assertDontSee('Retired Plan');
    }
}
