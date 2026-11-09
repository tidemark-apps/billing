<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\TwoFactor;
use App\Support\Settings;
use App\Support\TwoFactor\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** security.require_staff_2fa applies to every staff API call, to token creation, and to what happens to tokens on a 2FA reset. */
class StaffTwoFactorEnforcementTest extends TestCase
{
    use ApiTestHelpers, RefreshDatabase;

    private function require2fa(bool $on = true): void
    {
        Settings::set('security.require_staff_2fa', $on ? '1' : '0');
        Settings::flush();
    }

    private function enrol(User $user): string
    {
        $svc = app(TwoFactor::class);
        $secret = $svc->beginEnrollment($user);
        $svc->confirmEnrollment($user, Totp::code($secret, Totp::timestep()));

        return $secret;
    }

    public function test_existing_staff_token_stops_working_until_the_owner_enrols(): void
    {
        $staff = $this->staffUser();
        [, $plain] = ApiToken::issue($staff, 'old integration');
        $this->getJson('/api/v1/admin/me', ['Authorization' => "Bearer $plain"])->assertOk();

        $this->require2fa();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/me', ['Authorization' => "Bearer $plain"])->assertStatus(403)->assertJsonPath('error.code', 'two_factor_enrolment_required');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/clients', ['Authorization' => "Bearer $plain"])->assertStatus(403)->assertJsonPath('error.code', 'two_factor_enrolment_required');
        $this->app['auth']->forgetGuards();

        // enrolling lifts it
        $this->enrol($staff);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/me', ['Authorization' => "Bearer $plain"])->assertOk();
    }

    public function test_admins_are_covered_too_and_the_setting_off_changes_nothing(): void
    {
        $admin = $this->admin();
        $this->as($admin)->getJson('/api/v1/admin/me')->assertOk();
        $this->require2fa();
        $this->as($admin)->getJson('/api/v1/admin/me')->assertStatus(403)->assertJsonPath('error.code', 'two_factor_enrolment_required');
        $this->require2fa(false);
        $this->as($admin)->getJson('/api/v1/admin/me')->assertOk();
    }

    public function test_customers_and_enrolled_staff_are_unaffected(): void
    {
        $this->require2fa();
        $enrolled = $this->staffUser();
        $this->enrol($enrolled);
        $this->as($enrolled->fresh())->getJson('/api/v1/admin/me')->assertOk();
        $this->as($this->customer())->getJson('/api/v1/me')->assertOk();
    }

    public function test_creating_tokens_is_refused_for_unenrolled_staff_in_the_web_form_and_the_api(): void
    {
        $admin = $this->admin();
        $this->require2fa();

        $this->actingAs($admin)->post(route('admin.api.tokens.store'), ['name' => 'x', 'scope' => 'full'])->assertRedirect(route('admin.account.security.index'));
        $this->assertSame(0, ApiToken::count());

        // the API refuses at the middleware, before the controller
        $this->as($admin)->postJson('/api/v1/admin/api-tokens', ['name' => 'x'])->assertStatus(403)->assertJsonPath('error.code', 'two_factor_enrolment_required');
        $this->assertSame(1, ApiToken::count()); // only the helper's own token

        // an enrolled admin can
        $this->enrol($admin);
        $this->actingAs($admin->fresh())->post(route('admin.api.tokens.store'), ['name' => 'x', 'scope' => 'full'])->assertRedirect(route('admin.api.index'));
        $this->as($admin->fresh())->postJson('/api/v1/admin/api-tokens', ['name' => 'y'])->assertCreated();
    }

    public function test_admin_reset_keeps_api_tokens_by_default_and_revokes_them_on_request(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->staff()->create();
        $this->enrol($staff);
        ApiToken::issue($staff, 'a');
        ApiToken::issue($staff, 'b');

        $this->actingAs($admin)->post(route('admin.users.two-factor.reset', $staff))->assertSessionHas('status');
        $this->assertFalse($staff->fresh()->hasTwoFactor());
        $this->assertSame(2, $staff->apiTokens()->count());

        $this->enrol($staff->fresh());
        $this->actingAs($admin)->post(route('admin.users.two-factor.reset', $staff), ['revoke_tokens' => 1])->assertSessionHas('status');
        $this->assertSame(0, $staff->apiTokens()->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'two_factor.reset', 'subject_id' => $staff->id, 'description' => "{$admin->email} reset two-factor authentication for {$staff->email} and revoked 2 API token(s)"]);
    }

    public function test_self_disable_keeps_tokens(): void
    {
        $staff = $this->staffUser();
        $secret = $this->enrol($staff);
        ApiToken::issue($staff, 'mine');

        $this->actingAs($staff)->delete(route('admin.account.security.disable'), ['current_password' => 'password', 'code' => Totp::code($secret, Totp::timestep() + 1)])->assertSessionHasNoErrors();
        $this->assertFalse($staff->fresh()->hasTwoFactor());
        $this->assertSame(1, $staff->apiTokens()->count());
    }

    public function test_console_reset_can_revoke_tokens(): void
    {
        $user = $this->staffUser();
        $this->enrol($user);
        ApiToken::issue($user, 'mine');

        $this->artisan('billing:reset-2fa', ['email' => $user->email])->assertSuccessful();
        $this->assertSame(1, $user->apiTokens()->count());
        $this->enrol($user->fresh());
        $this->artisan('billing:reset-2fa', ['email' => $user->email, '--revoke-tokens' => true])->assertSuccessful();
        $this->assertSame(0, $user->apiTokens()->count());
    }

    public function test_unenrolled_staff_can_still_revoke_their_own_token(): void
    {
        $staff = $this->staffUser();
        [$token, $plain] = ApiToken::issue($staff, 'old integration');
        $this->require2fa();

        $this->deleteJson('/api/v1/auth/token', [], ['Authorization' => "Bearer $plain"])->assertSuccessful();
        $this->assertDatabaseMissing('api_tokens', ['id' => $token->id]);
    }

    public function test_client_page_reset_can_revoke_the_logins_tokens(): void
    {
        $admin = User::factory()->staff()->create(['role' => Role::Admin]);
        $customer = User::factory()->withClient()->create();
        $this->enrol($customer);
        ApiToken::issue($customer, 'portal token');

        $this->actingAs($admin)->get(route('admin.clients.show', $customer->client_id))->assertOk()->assertSee('name="revoke_tokens"', false);
        $this->actingAs($admin)->post(route('admin.users.two-factor.reset', $customer), ['revoke_tokens' => 1])->assertSessionHas('status');
        $this->assertSame(0, $customer->apiTokens()->count());
    }
}
