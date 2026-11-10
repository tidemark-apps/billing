<?php

namespace Tests\Feature\System;

use App\Enums\BillingCycle;
use App\Models\Client;
use App\Models\PluginRecord;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Plugins\Extensions;
use App\Plugins\Hooks;
use App\Plugins\ModuleRegistry;
use App\Plugins\PluginManager;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Support\Settings;
use Fixtures\Demo\DemoPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PluginSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.plugins_path' => base_path('tests/Fixtures/plugins')]);
        $this->app->forgetInstance(PluginManager::class);
        $this->app->singleton(PluginManager::class);
        Hooks::flush();
        app(PluginManager::class)->instance('demo'); // registers the fixture's autoloader
        DemoPlugin::$seen = [];
    }

    public function test_enabled_plugins_load_from_the_database_at_provider_register_time(): void
    {
        PluginRecord::create(['slug' => 'demo', 'enabled' => true, 'version' => '1.0.0', 'installed_at' => now()]);

        // What every request does: load enabled plugins before Eloquent has its connection resolver.
        $manager = new PluginManager;
        $manager->loadEnabled();

        $this->assertArrayHasKey('demo', $manager->loaded());
    }

    public function test_discovers_plugins_from_the_plugins_folder(): void
    {
        $found = app(PluginManager::class)->discover();

        $this->assertArrayHasKey('demo', $found);
        $this->assertSame('Demo plugin', $found['demo']['name']);
    }

    public function test_enabling_runs_migrations_and_registers_everything(): void
    {
        $this->enablePlugin('demo');

        $this->assertTrue(Schema::hasTable('demo_things'), 'plugin migrations run on enable');
        $this->assertTrue(PluginRecord::find('demo')->enabled);
        $this->assertNotNull(app(ModuleRegistry::class)->get('demo'), 'module registered');
        $this->assertTrue(Route::has('admin.demo.index'));
        $this->assertSame('demo.value+plugin', Hooks::apply('demo.value', 'demo.value'));
        $this->assertStringContainsString('demo-widget', (string) app(Extensions::class)->render('admin.dashboard'));
        $this->assertArrayHasKey('Demo', app(Extensions::class)->menu('admin'));
    }

    public function test_plugin_routes_are_mounted_in_their_areas_with_the_right_guards(): void
    {
        $this->enablePlugin('demo');

        $this->get('/x/demo/ping')->assertOk()->assertSee('pong');
        $this->get('/admin/x/demo')->assertRedirect('/login');
        $this->actingAs($this->customer())->get('/admin/x/demo')->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/x/demo')->assertOk()->assertSee('Demo plugin page');
    }

    public function test_domain_events_reach_plugin_listeners(): void
    {
        $this->enablePlugin('demo');
        $client = Client::factory()->create();
        $product = Product::factory()->priced(500)->create();
        ['invoice' => $invoice] = app(OrderService::class)->place($client, $product, BillingCycle::Monthly);

        app(PaymentService::class)->recordManual($invoice, $invoice->total);

        $this->assertSame([$invoice->number], DemoPlugin::$seen);
    }

    public function test_admin_can_enable_disable_and_configure_a_plugin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/plugins')->assertOk()->assertSee('Demo plugin');
        $this->actingAs($admin)->post('/admin/plugins/demo/enable')->assertRedirect();
        $this->assertTrue(app(PluginManager::class)->isEnabled('demo'));

        $this->actingAs($admin)->put('/admin/plugins/demo/settings', ['config' => ['greeting' => 'Howdy', 'api_key' => 'k-123']])->assertRedirect();
        $this->assertSame('Howdy', Settings::get('plugin.demo.greeting'));
        $this->assertSame('k-123', Settings::get('plugin.demo.api_key'));
        $this->assertSame(1, Setting::where('key', 'plugin.demo.api_key')->where('is_secret', true)->count(), 'password fields are stored encrypted');
        $this->assertNotSame('k-123', Setting::find('plugin.demo.api_key')->value);

        $this->actingAs($admin)->get('/admin/plugins/demo/settings')->assertOk()->assertDontSee('k-123');
        $this->actingAs($admin)->post('/admin/plugins/demo/disable')->assertRedirect();
        $this->assertFalse(app(PluginManager::class)->isEnabled('demo'));
    }

    public function test_staff_cannot_enable_plugins(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post('/admin/plugins/demo/enable')->assertForbidden();
    }

    public function test_plugin_list_has_state_tabs_with_counts(): void
    {
        $admin = $this->admin();
        $page = $this->actingAs($admin)->get('/admin/plugins')->assertOk()->assertSee('Enabled')->assertSee('Disabled')->assertSee('Updates pending');
        $this->assertStringContainsString('tab-count', $page->getContent());
        $this->actingAs($admin)->get('/admin/plugins?view=updates')->assertOk()->assertSee('No plugins in this view');
    }
}
