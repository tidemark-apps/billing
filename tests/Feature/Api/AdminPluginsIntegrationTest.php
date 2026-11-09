<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The plugin endpoints against the real PluginManager and a bundled plugin. */
class AdminPluginsIntegrationTest extends TestCase
{
    use ApiTestHelpers, RefreshDatabase;

    public function test_bundled_plugin_lifecycle(): void
    {
        $admin = $this->admin();
        $row = collect($this->as($admin)->getJson('/api/v1/admin/plugins')->assertOk()->json('data'))->firstWhere('slug', 'license-server');
        $this->assertFalse($row['enabled']);
        $this->assertTrue($row['configurable']);

        $this->as($admin)->putJson('/api/v1/admin/plugins/license-server/settings', ['settings' => ['default_grace_days' => 3]])->assertNotFound();
        $this->as($admin)->postJson('/api/v1/admin/plugins/license-server/enable')->assertOk()->assertJsonPath('data.enabled', true);
        $this->as($admin)->putJson('/api/v1/admin/plugins/license-server/settings', ['settings' => ['default_grace_days' => 3]])->assertOk();
        $fields = collect($this->as($admin)->getJson('/api/v1/admin/plugins/license-server/settings')->assertOk()->json('data.fields'))->keyBy('key');
        $this->assertEquals(3, $fields['default_grace_days']['value']);
        $this->as($admin)->postJson('/api/v1/admin/plugins/license-server/disable')->assertOk()->assertJsonPath('data.enabled', false);
        $this->as($admin)->getJson('/api/v1/admin/plugins/license-server/settings')->assertNotFound();
    }
}
