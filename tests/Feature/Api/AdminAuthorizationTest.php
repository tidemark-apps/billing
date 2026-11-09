<?php

namespace Tests\Feature\Api;

use App\Models\ActivityLog;
use App\Models\ApiToken;
use App\Models\Client;
use App\Models\WebhookEndpoint;
use App\Support\Abilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Authorization of the whole admin API, route by route, so a new route cannot ship unprotected. */
class AdminAuthorizationTest extends TestCase
{
    use ApiTestHelpers, RefreshDatabase;

    /** @return array<int, array{0: string, 1: string, 2: array<int, string>}> method, url, required abilities */
    private function adminRoutes(): array
    {
        $routes = [];
        foreach (app('router')->getRoutes() as $route) {
            /** @var Route $route */
            if (! str_starts_with($route->uri(), 'api/v1/admin')) {
                continue;
            }
            $abilities = [];
            foreach ($route->gatherMiddleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'ability:')) {
                    $abilities = array_merge($abilities, explode(',', substr($m, 8)));
                }
            }
            $url = '/'.preg_replace_callback('/\{([^}]+)\}/', fn ($m) => isset($route->wheres[$m[1]]) ? explode('|', $route->wheres[$m[1]])[0] : '1', $route->uri());
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $routes[] = [$method, $url, $abilities];
                }
            }
        }

        return $routes;
    }

    private function hit(string $method, string $url)
    {
        return $this->json($method, $url, []);
    }

    public function test_every_admin_route_requires_a_staff_token(): void
    {
        $customer = $this->customer();
        foreach ($this->adminRoutes() as [$method, $url]) {
            $this->flushHeaders();
            $this->hit($method, $url)->assertStatus(401, "$method $url without a token");
            $this->as($customer)->hit($method, $url)->assertStatus(403, "$method $url with a customer token");
        }
    }

    public function test_every_admin_route_enforces_its_ability(): void
    {
        $admin = $this->admin();
        $checked = 0;
        foreach ($this->adminRoutes() as [$method, $url, $abilities]) {
            if (! $abilities) {
                continue;
            }
            // a token that can do everything except what this route needs
            $without = array_values(array_diff(Abilities::staff(), $abilities));
            $this->as($admin, $without)->hit($method, $url)->assertStatus(403, "$method $url with a token lacking ".implode(',', $abilities));
            $checked++;
        }
        $this->assertGreaterThan(90, $checked);
    }

    public function test_every_documented_admin_route_exists_only_behind_staff_auth(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/admin')) {
                $this->assertContains('auth.api:staff', $route->gatherMiddleware(), $route->uri());
                $this->assertContains('throttle:api', $route->gatherMiddleware(), $route->uri());
            }
        }
    }

    public function test_unknown_ids_never_answer_before_authorization(): void
    {
        $this->flushHeaders()->getJson('/api/v1/admin/clients/999999')->assertStatus(401);
        $this->as($this->customer())->getJson('/api/v1/admin/clients/999999')->assertStatus(403);
        $this->as($this->admin(), ['invoices.read'])->getJson('/api/v1/admin/clients/999999')->assertStatus(403);
        $this->as($this->admin())->getJson('/api/v1/admin/clients/999999')->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    }

    public function test_webhook_management_needs_an_administrator(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $staff = $this->staffUser();
        $admin = $this->admin();
        $hook = WebhookEndpoint::create(['url' => 'https://hooks.example.com/in', 'secret' => 's', 'events' => ['*'], 'is_active' => true]);

        $this->as($staff)->getJson('/api/v1/admin/webhooks')->assertOk();
        $this->as($staff)->postJson('/api/v1/admin/webhooks', ['url' => 'https://x.example.com'])->assertStatus(403);
        $this->as($staff)->patchJson("/api/v1/admin/webhooks/{$hook->id}", ['is_active' => false])->assertStatus(403);
        $this->as($staff)->deleteJson("/api/v1/admin/webhooks/{$hook->id}")->assertStatus(403);
        $this->assertTrue($hook->refresh()->is_active);

        $this->as($admin)->postJson('/api/v1/admin/webhooks', ['url' => 'ftp://x.example.com'])->assertStatus(422); // http(s) only, like the screen
        $this->as($admin)->patchJson("/api/v1/admin/webhooks/{$hook->id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->as($admin)->deleteJson("/api/v1/admin/webhooks/{$hook->id}")->assertNoContent();
        $this->assertDatabaseHas('activity_logs', ['action' => 'webhook.deleted']);
    }

    public function test_write_endpoints_leave_an_audit_trail_marked_as_api(): void
    {
        $admin = $this->admin();
        $client = Client::factory()->create();
        $this->as($admin)->patchJson("/api/v1/admin/clients/{$client->id}", ['notes' => 'x'])->assertOk();
        $log = ActivityLog::where('action', 'client.updated')->latest('id')->first();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('api', $log->properties['via']);
        $this->assertSame(1, ApiToken::where('user_id', $admin->id)->count());
    }
}
