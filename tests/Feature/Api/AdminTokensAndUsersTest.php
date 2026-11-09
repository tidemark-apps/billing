<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\Client;
use App\Models\User;
use App\Support\Abilities;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** API tokens, abilities, portal logins of clients, and impersonation. */
class AdminTokensAndUsersTest extends TestCase
{
    use ApiTestHelpers, RefreshDatabase;

    public function test_abilities_catalogue_and_me(): void
    {
        $staff = $this->staffUser();
        $res = $this->as($staff)->getJson('/api/v1/admin/abilities')->assertOk()->assertJsonStructure(['data' => ['customer', 'staff']]);
        $this->assertSame(Abilities::staff(), array_column($res->json('data.staff'), 'name'));
        $this->assertSame(Abilities::customer(), array_column($res->json('data.customer'), 'name'));

        $this->as($staff, ['clients.read'])->getJson('/api/v1/admin/me')->assertOk()->assertJsonPath('data.email', $staff->email)->assertJsonPath('data.token.abilities', ['clients.read'])->assertJsonPath('data.token.full_access', false);
        $this->as($this->customer())->getJson('/api/v1/admin/me')->assertStatus(403);
    }

    public function test_every_ability_used_by_a_route_is_in_the_central_list(): void
    {
        $used = [];
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }
            foreach ($route->gatherMiddleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'ability:')) {
                    $used = array_merge($used, explode(',', substr($m, 8)));
                }
            }
        }
        $this->assertSame([], array_values(array_diff(array_unique($used), Abilities::all())));
        // and every ability in the list is used by some route (no dead entries)
        $this->assertSame([], array_values(array_diff(Abilities::all(), array_unique($used))));
    }

    public function test_token_issuing_validates_abilities_for_the_account_type(): void
    {
        $user = User::factory()->withClient()->create(['email' => 'jane@example.com']);
        $staff = User::factory()->staff()->create(['email' => 'sam@example.com']);

        $this->postJson('/api/v1/auth/token', ['email' => 'jane@example.com', 'password' => 'password', 'abilities' => ['invoices.read', 'nonsense']])->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['details' => ['abilities']]]);
        $this->postJson('/api/v1/auth/token', ['email' => 'jane@example.com', 'password' => 'password', 'abilities' => ['clients.write']])->assertStatus(422); // a staff ability on a customer
        $this->postJson('/api/v1/auth/token', ['email' => 'jane@example.com', 'password' => 'password', 'abilities' => ['invoices.read']])->assertCreated()->assertJsonPath('data.abilities', ['invoices.read']);
        $this->postJson('/api/v1/auth/token', ['email' => 'sam@example.com', 'password' => 'password', 'abilities' => ['clients.write', 'settings.read']])->assertCreated();
        $this->postJson('/api/v1/auth/token', ['email' => 'sam@example.com', 'password' => 'password', 'abilities' => ['orders.write']])->assertStatus(422);
        $this->assertNotNull($user->id);
        $this->assertNotNull($staff->id);
    }

    public function test_admin_creates_lists_and_revokes_tokens(): void
    {
        $admin = $this->admin();
        $res = $this->as($admin)->postJson('/api/v1/admin/api-tokens', ['name' => 'CI', 'abilities' => ['invoices.read', 'invoices.read'], 'expires_in_days' => 30])->assertCreated()
            ->assertJsonPath('data.abilities', ['invoices.read'])->assertJsonPath('data.full_access', false)->assertJsonPath('data.user_id', $admin->id);
        $plain = $res->json('data.token');
        $id = $res->json('data.id');
        $this->assertStringStartsWith('tmb_', $plain);
        $this->assertNotNull($res->json('data.expires_at'));

        // the new token works, with exactly its abilities
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken($plain)->getJson('/api/v1/admin/invoices')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/admin/clients')->assertStatus(403);

        $list = $this->as($admin)->getJson('/api/v1/admin/api-tokens')->assertOk();
        $this->assertStringNotContainsString($plain, $list->getContent());
        $this->assertStringNotContainsString('token_hash', $list->getContent());
        $this->as($admin)->getJson("/api/v1/admin/api-tokens/$id")->assertOk()->assertJsonMissingPath('data.token')->assertJsonMissingPath('data.token_hash');

        $this->as($admin)->postJson('/api/v1/admin/api-tokens', ['name' => 'full'])->assertCreated()->assertJsonPath('data.full_access', true)->assertJsonPath('data.abilities', null);
        $this->as($admin)->postJson('/api/v1/admin/api-tokens', [])->assertStatus(422);
        $this->as($admin)->postJson('/api/v1/admin/api-tokens', ['name' => 'x', 'abilities' => ['orders.write']])->assertStatus(422); // customer ability
        $this->as($admin)->postJson('/api/v1/admin/api-tokens', ['name' => 'x', 'abilities' => ['made.up']])->assertStatus(422);

        $this->as($admin)->deleteJson("/api/v1/admin/api-tokens/$id")->assertNoContent();
        $this->assertNull(ApiToken::find($id));
        $this->assertDatabaseHas('activity_logs', ['action' => 'api.token.revoked']);
    }

    public function test_tokens_can_only_grant_what_the_creating_token_has(): void
    {
        $admin = $this->admin();
        $this->as($admin, ['api-tokens.write', 'invoices.read'])->postJson('/api/v1/admin/api-tokens', ['name' => 'child', 'abilities' => ['invoices.read']])->assertCreated();
        $this->as($admin, ['api-tokens.write', 'invoices.read'])->postJson('/api/v1/admin/api-tokens', ['name' => 'child', 'abilities' => ['settings.write']])->assertStatus(403);
        $this->as($admin, ['api-tokens.write', 'invoices.read'])->postJson('/api/v1/admin/api-tokens', ['name' => 'child'])->assertStatus(403); // would be full access
        $this->as($admin, ['api-tokens.read'])->postJson('/api/v1/admin/api-tokens', ['name' => 'child', 'abilities' => ['api-tokens.read']])->assertStatus(403);
    }

    public function test_admins_can_see_and_revoke_other_users_tokens(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        [$theirs] = ApiToken::issue($customer, 'storefront');
        $this->as($admin)->getJson('/api/v1/admin/api-tokens')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_current', true); // only your own
        $this->as($admin)->getJson('/api/v1/admin/api-tokens?user_id='.$customer->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'storefront')->assertJsonPath('data.0.user.email', $customer->email);
        $this->assertContains('storefront', array_column($this->as($admin)->getJson('/api/v1/admin/api-tokens?all=1')->assertOk()->json('data'), 'name'));
        $this->as($admin)->deleteJson('/api/v1/admin/api-tokens/'.$theirs->id)->assertNoContent();
    }

    public function test_token_management_is_for_administrators_with_the_ability(): void
    {
        $staff = $this->staffUser();
        $customer = $this->customer();
        $admin = $this->admin();
        [$token] = ApiToken::issue($admin, 'x');

        foreach ([$staff, $customer] as $user) {
            $this->as($user)->getJson('/api/v1/admin/api-tokens')->assertStatus(403);
            $this->as($user)->postJson('/api/v1/admin/api-tokens', ['name' => 'x'])->assertStatus(403);
            $this->as($user)->deleteJson('/api/v1/admin/api-tokens/'.$token->id)->assertStatus(403);
        }
        $this->assertNotNull(ApiToken::find($token->id));
        $this->flushHeaders()->getJson('/api/v1/admin/api-tokens')->assertStatus(401);
        $this->as($admin, ['api-tokens.read'])->getJson('/api/v1/admin/api-tokens')->assertOk();
        $this->as($admin, ['api-tokens.read'])->deleteJson('/api/v1/admin/api-tokens/'.$token->id)->assertStatus(403);
        $this->as($admin, ['clients.read'])->getJson('/api/v1/admin/api-tokens')->assertStatus(403);
    }

    public function test_client_logins_lifecycle(): void
    {
        $admin = $this->admin();
        $client = Client::factory()->create();
        $id = $this->as($admin)->postJson("/api/v1/admin/clients/{$client->id}/users", ['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'longenough1'])->assertCreated()
            ->assertJsonPath('data.role', 'customer')->assertJsonPath('data.client_id', $client->id)->json('data.id');
        $this->as($admin)->postJson("/api/v1/admin/clients/{$client->id}/users", ['name' => 'Dup', 'email' => 'bob@example.com', 'password' => 'longenough1'])->assertStatus(422);

        $this->as($admin)->getJson("/api/v1/admin/clients/{$client->id}/users")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'bob@example.com');
        $this->as($admin)->getJson("/api/v1/admin/users/$id")->assertOk()->assertJsonPath('data.name', 'Bob');
        $this->as($admin)->patchJson("/api/v1/admin/users/$id", ['name' => 'Robert', 'email' => 'robert@example.com'])->assertOk()->assertJsonPath('data.name', 'Robert');
        $this->as($admin)->patchJson("/api/v1/admin/users/$id", ['email' => $admin->email])->assertStatus(422);

        // password reset: the new password works, old API tokens are revoked
        [$old] = ApiToken::issue(User::find($id), 'old');
        $this->as($admin)->postJson("/api/v1/admin/users/$id/password", ['password' => 'short'])->assertStatus(422);
        $this->as($admin)->postJson("/api/v1/admin/users/$id/password", ['password' => 'brand-new-pass-1'])->assertOk();
        $this->assertNull(ApiToken::find($old->id));
        $this->flushHeaders()->postJson('/api/v1/auth/token', ['email' => 'robert@example.com', 'password' => 'brand-new-pass-1'])->assertCreated();

        // deactivate: cannot sign in any more, tokens revoked; activate restores
        [$tok] = ApiToken::issue(User::find($id), 'live');
        $this->as($admin)->postJson("/api/v1/admin/users/$id/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertNull(ApiToken::find($tok->id));
        $this->flushHeaders()->postJson('/api/v1/auth/token', ['email' => 'robert@example.com', 'password' => 'brand-new-pass-1'])->assertStatus(401);
        $this->as($admin)->getJson("/api/v1/admin/clients/{$client->id}/users?is_active=1")->assertJsonCount(0, 'data');
        $this->as($admin)->postJson("/api/v1/admin/users/$id/activate")->assertOk()->assertJsonPath('data.is_active', true);
        $this->flushHeaders()->postJson('/api/v1/auth/token', ['email' => 'robert@example.com', 'password' => 'brand-new-pass-1'])->assertCreated();

        $this->assertDatabaseHas('activity_logs', ['action' => 'client.contact_deactivated']);
    }

    public function test_reset_link_is_emailed(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $login = $this->customer();
        $this->as($admin)->postJson("/api/v1/admin/users/{$login->id}/password-reset-link")->assertOk()->assertJsonPath('data.success', true);
        Notification::assertSentTo($login, ResetPassword::class);
    }

    public function test_staff_accounts_are_not_reachable_through_the_login_endpoints(): void
    {
        $admin = $this->admin();
        $other = $this->staffUser();
        $this->as($admin)->getJson("/api/v1/admin/users/{$other->id}")->assertNotFound();
        $this->as($admin)->patchJson("/api/v1/admin/users/{$other->id}", ['name' => 'x'])->assertNotFound();
        $this->as($admin)->postJson("/api/v1/admin/users/{$other->id}/password", ['password' => 'longenough1'])->assertNotFound();
        $this->as($admin)->postJson("/api/v1/admin/users/{$admin->id}/deactivate")->assertNotFound();
        $this->as($admin)->postJson("/api/v1/admin/users/{$other->id}/impersonate")->assertStatus(403);
    }

    public function test_impersonation_issues_a_short_lived_customer_token(): void
    {
        $admin = $this->admin();
        $login = $this->customer();
        $this->makeInvoice($login->client);

        $res = $this->as($admin)->postJson("/api/v1/admin/users/{$login->id}/impersonate", ['minutes' => 5, 'abilities' => ['invoices.read']])->assertCreated()
            ->assertJsonPath('data.user.email', $login->email)->assertJsonPath('data.abilities', ['invoices.read']);
        $token = ApiToken::findPlain($res->json('data.token'));
        $this->assertTrue($token->expires_at->between(now()->addMinutes(4), now()->addMinutes(6)));
        $this->assertStringContainsString($admin->email, $token->name);
        $this->assertDatabaseHas('activity_logs', ['action' => 'impersonation.started', 'subject_id' => $login->id]);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken($res->json('data.token'))->getJson('/api/v1/invoices')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($res->json('data.token'))->getJson('/api/v1/quotes')->assertStatus(403); // limited to invoices.read
        $this->app['auth']->forgetGuards();
        $this->withToken($res->json('data.token'))->getJson('/api/v1/admin/clients')->assertStatus(403);

        // default lifetime is 15 minutes, can never exceed an hour; unknown / staff abilities are rejected
        $default = $this->as($admin)->postJson("/api/v1/admin/users/{$login->id}/impersonate")->assertCreated();
        $this->assertTrue(ApiToken::findPlain($default->json('data.token'))->expires_at->between(now()->addMinutes(14), now()->addMinutes(16)));
        $this->as($admin)->postJson("/api/v1/admin/users/{$login->id}/impersonate", ['minutes' => 600])->assertStatus(422);
        $this->as($admin)->postJson("/api/v1/admin/users/{$login->id}/impersonate", ['abilities' => ['settings.write']])->assertStatus(422);

        // not for inactive logins, and a limited token cannot hand out abilities it lacks
        $login->update(['is_active' => false]);
        $this->as($admin)->postJson("/api/v1/admin/users/{$login->id}/impersonate")->assertStatus(403);
        $login->update(['is_active' => true]);
        $this->as($admin, ['impersonation.write', 'invoices.read'])->postJson("/api/v1/admin/users/{$login->id}/impersonate", ['abilities' => ['quotes.read']])->assertStatus(403);
    }

    public function test_user_endpoints_authorization(): void
    {
        $login = $this->customer();
        $admin = $this->admin();
        $client = $login->client;

        $this->as($login)->getJson("/api/v1/admin/clients/{$client->id}/users")->assertStatus(403);
        $this->as($login)->postJson("/api/v1/admin/users/{$login->id}/impersonate")->assertStatus(403);
        $this->as($login)->postJson("/api/v1/admin/users/{$login->id}/password", ['password' => 'longenough1'])->assertStatus(403);
        $this->flushHeaders()->getJson("/api/v1/admin/users/{$login->id}")->assertStatus(401);

        $this->as($admin, ['clients.read'])->getJson("/api/v1/admin/clients/{$client->id}/users")->assertOk();
        $this->as($admin, ['clients.read'])->postJson("/api/v1/admin/users/{$login->id}/deactivate")->assertStatus(403);
        $this->as($admin, ['clients.read'])->postJson("/api/v1/admin/users/{$login->id}/impersonate")->assertStatus(403);
        $this->as($admin, ['impersonation.write'])->getJson("/api/v1/admin/users/{$login->id}")->assertStatus(403);
        $this->as($admin, ['clients.write'])->postJson("/api/v1/admin/users/{$login->id}/impersonate")->assertStatus(403);
    }

    public function test_deleting_a_client_with_live_services_or_open_invoices_is_refused(): void
    {
        $admin = $this->admin();
        $client = Client::factory()->create();
        $service = $this->makeService($client);

        $this->as($admin)->deleteJson("/api/v1/admin/clients/{$client->id}")->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $service->update(['status' => 'terminated']);
        $invoice = $this->makeInvoice($client);
        $this->as($admin)->deleteJson("/api/v1/admin/clients/{$client->id}")->assertStatus(422);
        $invoice->update(['status' => 'void']);
        $this->as($admin)->deleteJson("/api/v1/admin/clients/{$client->id}")->assertNoContent();
        $this->assertDatabaseHas('activity_logs', ['action' => 'client.deleted']);
    }

    public function test_client_validation_matches_the_admin_screen_and_notes_status_are_editable(): void
    {
        $admin = $this->admin();
        $client = Client::factory()->create();

        $this->as($admin)->patchJson("/api/v1/admin/clients/{$client->id}", ['status' => 'inactive', 'notes' => 'Pays late'])->assertOk()->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.notes', 'Pays late');
        $this->as($admin)->patchJson("/api/v1/admin/clients/{$client->id}", ['status' => 'vip'])->assertStatus(422);
        $this->as($admin)->patchJson("/api/v1/admin/clients/{$client->id}", ['country' => 'ZZ'])->assertStatus(422);
        $this->as($admin)->patchJson("/api/v1/admin/clients/{$client->id}", ['currency' => 'XXX'])->assertStatus(422);
        $this->as($admin)->patchJson("/api/v1/admin/clients/{$client->id}", ['country' => 'de', 'currency' => 'eur'])->assertOk()->assertJsonPath('data.currency', 'EUR');
        $this->as($admin)->getJson("/api/v1/admin/clients/{$client->id}/tax-rate")->assertOk()->assertJsonStructure(['data' => ['rate', 'name', 'currency']])->assertJsonPath('data.currency', 'EUR');
    }
}
