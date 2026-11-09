<?php

namespace Tests\Feature\Admin;

use App\Models\Server;
use App\Plugins\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeServerModule;
use Tests\TestCase;

class ServersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRegistry::class)->register(new FakeServerModule);
    }

    public function test_create_server_with_module_config_and_keep_secret_on_update(): void
    {
        $this->actingAs($this->admin())->post(route('admin.servers.store'), [
            'module' => 'fakeserver', 'name' => 'S1', 'hostname' => 'good.example.com', 'username' => 'root', 'secret' => 'tok123',
            'use_ssl' => 1, 'verify_ssl' => 1, 'is_active' => 1, 'config' => ['reseller' => 'acme', 'token' => 'cfg-secret'],
        ])->assertRedirect(route('admin.servers.index'));
        $s = Server::firstOrFail();
        $this->assertSame('tok123', $s->secret);
        $this->assertSame('acme', $s->option('reseller'));

        $this->put(route('admin.servers.update', $s), ['module' => 'fakeserver', 'name' => 'S1b', 'hostname' => 'good.example.com', 'secret' => '', 'is_active' => 1, 'config' => ['reseller' => 'new', 'token' => '']])->assertRedirect();
        $s->refresh();
        $this->assertSame('tok123', $s->secret);
        $this->assertSame('cfg-secret', $s->option('token'));
        $this->assertSame('new', $s->option('reseller'));
        $this->assertSame('S1b', $s->name);
    }

    public function test_test_action_reports_success_and_failure(): void
    {
        $admin = $this->admin();
        $good = Server::create(['module' => 'fakeserver', 'name' => 'g', 'hostname' => 'good.example.com']);
        $bad = Server::create(['module' => 'fakeserver', 'name' => 'b', 'hostname' => 'bad.example.com']);
        $this->actingAs($admin)->post(route('admin.servers.test', $good))->assertSessionHas('status');
        $this->post(route('admin.servers.test', $bad))->assertSessionHas('error');
    }

    public function test_pages_render_and_unknown_modules_are_rejected(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.servers.create'))->assertOk()->assertSee('Fake server module');
        $this->get(route('admin.servers.index'))->assertOk();
        $this->post(route('admin.servers.store'), ['module' => 'nope', 'name' => 'x', 'hostname' => 'h.example.com'])->assertSessionHasErrors('module');
    }
}
