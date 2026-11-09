<?php

namespace Tests\Feature\Api;

use App\Models\Setting;
use App\Plugins\Plugin;
use App\Plugins\PluginManager;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Plugin list/state/settings through the API. A fake PluginManager keeps this independent of the bundled plugins. */
class AdminPluginsTest extends TestCase
{
    use ApiTestHelpers, RefreshDatabase;

    private object $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $plugin = new class(['slug' => 'demo', 'name' => 'Demo'], '/tmp') extends Plugin
        {
            public function fields(): array
            {
                return [
                    ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true],
                    ['key' => 'region', 'label' => 'Region', 'type' => 'select', 'options' => ['eu' => 'EU', 'us' => 'US']],
                    ['key' => 'verbose', 'label' => 'Verbose', 'type' => 'checkbox'],
                    ['key' => 'retries', 'label' => 'Retries', 'type' => 'number'],
                ];
            }
        };
        $this->manager = new class($plugin) extends PluginManager
        {
            public bool $enabled = false;

            public bool $failToEnable = false;

            public function __construct(private readonly Plugin $plugin) {}

            public function discover(): array
            {
                return [
                    'demo' => ['slug' => 'demo', 'name' => 'Demo plugin', 'version' => '1.2.0', 'description' => 'A demo', 'author' => 'Me', 'origin' => 'bundled', 'path' => '/secret/path'],
                    'plain' => ['slug' => 'plain', 'name' => 'Plain', 'version' => '1.0.0'],
                ];
            }

            public function manifest(string $slug): ?array
            {
                return $this->discover()[$slug] ?? null;
            }

            public function states(): array
            {
                return ['demo' => $this->enabled];
            }

            public function isEnabled(string $slug): bool
            {
                return $slug === 'demo' && $this->enabled;
            }

            public function instance(string $slug): ?Plugin
            {
                return $slug === 'demo' ? $this->plugin : null;
            }

            public function enable(string $slug): void
            {
                if ($this->failToEnable) {
                    throw new \RuntimeException('migration failed');
                }
                $this->enabled = true;
            }

            public function disable(string $slug): void
            {
                $this->enabled = false;
            }
        };
        $this->app->instance(PluginManager::class, $this->manager);
    }

    public function test_list_and_show_plugins(): void
    {
        $staff = $this->staffUser();
        $res = $this->as($staff)->getJson('/api/v1/admin/plugins')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.slug', 'demo')->assertJsonPath('data.0.enabled', false)
            ->assertJsonPath('data.0.configurable', true)->assertJsonPath('data.1.configurable', false)->assertJsonPath('data.0.version', '1.2.0');
        $this->assertStringNotContainsString('/secret/path', $res->getContent(), 'filesystem paths are not exposed');

        $this->as($staff)->getJson('/api/v1/admin/plugins/demo')->assertOk()->assertJsonPath('data.name', 'Demo plugin')->assertJsonPath('data.author', 'Me');
        $this->as($staff)->getJson('/api/v1/admin/plugins/missing')->assertNotFound();
    }

    public function test_enable_and_disable_need_an_administrator(): void
    {
        $admin = $this->admin();
        $this->as($this->staffUser())->postJson('/api/v1/admin/plugins/demo/enable')->assertStatus(403);
        $this->as($this->customer())->postJson('/api/v1/admin/plugins/demo/enable')->assertStatus(403);
        $this->as($admin, ['plugins.read'])->postJson('/api/v1/admin/plugins/demo/enable')->assertStatus(403);
        $this->assertFalse($this->manager->enabled);

        $this->as($admin)->postJson('/api/v1/admin/plugins/demo/enable')->assertOk()->assertJsonPath('data.enabled', true);
        $this->assertDatabaseHas('activity_logs', ['action' => 'plugin.enabled']);
        $this->as($admin)->postJson('/api/v1/admin/plugins/demo/disable')->assertOk()->assertJsonPath('data.enabled', false);
        $this->as($admin)->postJson('/api/v1/admin/plugins/nope/enable')->assertNotFound();

        $this->manager->failToEnable = true;
        $this->as($admin)->postJson('/api/v1/admin/plugins/demo/enable')->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
    }

    public function test_plugin_settings_are_masked_partial_and_validated(): void
    {
        $admin = $this->admin();
        $this->as($admin)->getJson('/api/v1/admin/plugins/demo/settings')->assertNotFound(); // not enabled
        $this->manager->enabled = true;

        $this->as($admin)->putJson('/api/v1/admin/plugins/demo/settings', ['settings' => ['api_key' => 'sekret-123', 'region' => 'eu', 'verbose' => true, 'retries' => 3]])->assertOk();
        $this->assertSame('sekret-123', Settings::get('plugin.demo.api_key'));
        $this->assertTrue((bool) Setting::where('key', 'plugin.demo.api_key')->value('is_secret'));

        $res = $this->as($this->staffUser())->getJson('/api/v1/admin/plugins/demo/settings')->assertOk();
        $this->assertStringNotContainsString('sekret-123', $res->getContent());
        $fields = collect($res->json('data.fields'))->keyBy('key');
        $this->assertTrue($fields['api_key']['has_value']);
        $this->assertArrayNotHasKey('value', $fields['api_key']);
        $this->assertSame('eu', $fields['region']['value']);
        $this->assertTrue($fields['verbose']['value']);

        // partial update keeps the secret and the checkbox
        $this->as($admin)->putJson('/api/v1/admin/plugins/demo/settings', ['settings' => ['region' => 'us']])->assertOk()->assertJsonPath('data.slug', 'demo');
        $this->assertSame('sekret-123', Settings::get('plugin.demo.api_key'));
        $this->assertTrue(Settings::bool('plugin.demo.verbose'));
        $this->assertSame('us', Settings::get('plugin.demo.region'));
        $this->as($admin)->putJson('/api/v1/admin/plugins/demo/settings', ['settings' => ['region' => null]])->assertOk();
        $this->assertNull(Settings::get('plugin.demo.region'));

        $this->as($admin)->putJson('/api/v1/admin/plugins/demo/settings', ['settings' => ['region' => 'moon']])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['settings.region']]]);
        $this->as($admin)->putJson('/api/v1/admin/plugins/demo/settings', ['settings' => ['retries' => 'many']])->assertStatus(422);
        $this->as($admin)->putJson('/api/v1/admin/plugins/demo/settings', ['settings' => ['nope' => 1]])->assertStatus(422);
        $this->as($admin)->putJson('/api/v1/admin/plugins/demo/settings', [])->assertStatus(422);
        $this->as($this->staffUser())->putJson('/api/v1/admin/plugins/demo/settings', ['settings' => ['region' => 'eu']])->assertStatus(403);
        $this->as($admin, ['plugins.read'])->putJson('/api/v1/admin/plugins/demo/settings', ['settings' => ['region' => 'eu']])->assertStatus(403);
        $this->as($this->customer())->getJson('/api/v1/admin/plugins/demo/settings')->assertStatus(403);
        $this->as($admin, ['clients.read'])->getJson('/api/v1/admin/plugins')->assertStatus(403);
    }
}
