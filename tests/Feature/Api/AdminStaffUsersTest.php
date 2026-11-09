<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\User;
use App\Services\TwoFactor;
use App\Support\TwoFactor\Totp;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** /admin/staff-users: staff accounts, administrators only. */
class AdminStaffUsersTest extends TestCase
{
    use ApiTestHelpers, RefreshDatabase;

    private function enrol(User $user): void
    {
        $svc = app(TwoFactor::class);
        $svc->confirmEnrollment($user, Totp::code($svc->beginEnrollment($user), Totp::timestep()));
    }

    public function test_authorization(): void
    {
        $staff = $this->staffUser();
        $target = User::factory()->staff()->create();

        $this->getJson('/api/v1/admin/staff-users')->assertStatus(401);
        $this->as($this->customer())->getJson('/api/v1/admin/staff-users')->assertStatus(403);
        // a staff token with the ability still needs an administrator
        foreach ([['getJson', ''], ['getJson', "/{$target->id}"], ['postJson', ''], ['patchJson', "/{$target->id}"], ['postJson', "/{$target->id}/activate"],
            ['postJson', "/{$target->id}/deactivate"], ['postJson', "/{$target->id}/password-reset-link"], ['postJson', "/{$target->id}/two-factor/reset"]] as [$method, $path]) {
            $this->as($staff)->$method('/api/v1/admin/staff-users'.$path, $method === 'getJson' ? [] : ['name' => 'x', 'email' => 'x@example.com', 'role' => 'admin', 'password' => 'longenough1'])->assertStatus(403);
        }
        // abilities
        $admin = $this->admin();
        $this->as($admin, ['clients.read'])->getJson('/api/v1/admin/staff-users')->assertStatus(403);
        $this->as($admin, ['staff-users.read'])->getJson('/api/v1/admin/staff-users')->assertOk();
        $this->as($admin, ['staff-users.read'])->postJson("/api/v1/admin/staff-users/{$target->id}/deactivate")->assertStatus(403);
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_list_show_and_filters_include_only_staff(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->staff()->create(['is_active' => false]);
        $this->enrol($admin);
        $client = $this->customer();

        $res = $this->as($admin)->getJson('/api/v1/admin/staff-users')->assertOk();
        $ids = array_column($res->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$admin->id, $staff->id], $ids);
        $this->assertTrue(collect($res->json('data'))->firstWhere('id', $admin->id)['two_factor_enabled']);
        $this->assertSame([$staff->id], array_column($this->as($admin)->getJson('/api/v1/admin/staff-users?is_active=0')->json('data'), 'id'));
        $this->assertSame([$admin->id], array_column($this->as($admin)->getJson('/api/v1/admin/staff-users?role=admin')->json('data'), 'id'));
        $this->as($admin)->getJson("/api/v1/admin/staff-users/{$staff->id}")->assertOk()->assertJsonPath('data.role', 'staff')->assertJsonPath('data.is_active', false);
        $this->as($admin)->getJson("/api/v1/admin/staff-users/{$client->id}")->assertNotFound();
        $this->as($admin)->getJson('/api/v1/admin/staff-users/99999')->assertNotFound();
    }

    public function test_create_update_with_password_or_link(): void
    {
        Notification::fake();
        $admin = $this->admin();

        $this->as($admin)->postJson('/api/v1/admin/staff-users', ['name' => 'Sam', 'email' => 'sam@example.com', 'role' => 'staff', 'password' => 'a-good-password'])
            ->assertCreated()->assertJsonPath('data.role', 'staff')->assertJsonPath('data.is_active', true);
        $this->as($admin)->postJson('/api/v1/admin/staff-users', ['name' => 'Ada', 'email' => 'ada@example.com', 'role' => 'admin', 'send_link' => true])->assertCreated();
        Notification::assertSentTo(User::where('email', 'ada@example.com')->first(), ResetPassword::class);

        $this->as($admin)->postJson('/api/v1/admin/staff-users', ['name' => 'No', 'email' => 'no@example.com', 'role' => 'staff'])->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $this->as($admin)->postJson('/api/v1/admin/staff-users', ['name' => 'No', 'email' => 'sam@example.com', 'role' => 'customer', 'password' => 'short'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->assertDatabaseMissing('users', ['email' => 'no@example.com']);

        $sam = User::where('email', 'sam@example.com')->first();
        $this->as($admin)->patchJson("/api/v1/admin/staff-users/{$sam->id}", ['name' => 'Samuel', 'role' => 'admin'])->assertOk()->assertJsonPath('data.name', 'Samuel')->assertJsonPath('data.role', 'admin');
        $this->assertDatabaseHas('activity_logs', ['action' => 'staff.created', 'subject_id' => $sam->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'staff.role_changed', 'subject_id' => $sam->id]);
    }

    public function test_last_administrator_and_self_protection(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->staff()->create();

        $this->as($admin)->patchJson("/api/v1/admin/staff-users/{$admin->id}", ['role' => 'staff'])->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $this->assertTrue($admin->fresh()->isAdmin());
        $this->as($admin)->postJson("/api/v1/admin/staff-users/{$admin->id}/deactivate")->assertStatus(422);
        $this->assertTrue($admin->fresh()->is_active);

        // with a second administrator the rule about the last one is satisfied, but nobody deactivates themselves
        $second = $this->admin();
        $this->as($admin)->postJson("/api/v1/admin/staff-users/{$admin->id}/deactivate")->assertStatus(422)->assertJsonPath('error.message', 'You cannot deactivate your own account.');
        $this->as($admin)->patchJson("/api/v1/admin/staff-users/{$second->id}", ['role' => 'staff'])->assertOk();
        $this->assertSame($staff->role, $second->fresh()->role);
    }

    public function test_deactivate_revokes_tokens_and_activate_restores(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->staff()->create();
        ApiToken::issue($staff, 'a');

        $this->as($admin)->postJson("/api/v1/admin/staff-users/{$staff->id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame(0, $staff->apiTokens()->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'staff.deactivated', 'subject_id' => $staff->id]);
        $this->as($admin)->postJson("/api/v1/admin/staff-users/{$staff->id}/activate")->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_password_link_and_two_factor_reset(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $staff = User::factory()->staff()->create();
        $this->enrol($staff);
        ApiToken::issue($staff, 'a');

        $this->as($admin)->postJson("/api/v1/admin/staff-users/{$staff->id}/password-reset-link")->assertOk()->assertJsonPath('data.success', true);
        Notification::assertSentTo($staff, ResetPassword::class);

        $this->as($admin)->postJson("/api/v1/admin/staff-users/{$staff->id}/two-factor/reset")->assertOk()->assertJsonPath('data.two_factor_enabled', false);
        $this->assertSame(1, $staff->apiTokens()->count());
        $this->enrol($staff->fresh());
        $this->as($admin)->postJson("/api/v1/admin/staff-users/{$staff->id}/two-factor/reset", ['revoke_api_tokens' => true])->assertOk();
        $this->assertSame(0, $staff->apiTokens()->count());

        // never for yourself
        $this->as($admin)->postJson("/api/v1/admin/staff-users/{$admin->id}/two-factor/reset")->assertStatus(403);
    }
}
