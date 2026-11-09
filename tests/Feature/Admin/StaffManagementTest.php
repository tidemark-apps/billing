<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\StaffAccounts;
use App\Services\TwoFactor;
use App\Support\TwoFactor\Totp;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    private function enrol(User $user): void
    {
        $svc = app(TwoFactor::class);
        $svc->confirmEnrollment($user, Totp::code($svc->beginEnrollment($user), Totp::timestep()));
    }

    public function test_only_administrators_can_open_staff_management(): void
    {
        $staff = User::factory()->staff()->create();
        $target = User::factory()->staff()->create();

        $this->get(route('admin.staff.index'))->assertRedirect(route('login'));
        $this->actingAs($staff)->get(route('admin.staff.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.staff.create'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.staff.store'), ['name' => 'X', 'email' => 'x@example.com', 'role' => 'admin', 'password_mode' => 'set', 'password' => 'longenough1'])->assertForbidden();
        $this->actingAs($staff)->put(route('admin.staff.update', $target), ['name' => 'X', 'email' => 'x@example.com', 'role' => 'admin'])->assertForbidden();
        $this->actingAs($staff)->post(route('admin.staff.deactivate', $target))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.staff.password-reset-link', $target))->assertForbidden();
        $this->actingAs($this->customer())->get(route('admin.staff.index'))->assertForbidden();
        $this->assertSame(Role::Staff, $target->fresh()->role);
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_sidebar_entry_is_for_administrators_only(): void
    {
        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertSee(route('admin.staff.index'), false);
        $this->actingAs(User::factory()->staff()->create())->get(route('admin.dashboard'))->assertOk()->assertDontSee(route('admin.staff.index'), false);
    }

    public function test_list_shows_staff_only_with_status_columns(): void
    {
        $admin = $this->admin();
        $withTfa = User::factory()->staff()->create(['name' => 'Tina Twofactor', 'last_login_at' => now()->subHour()]);
        $this->enrol($withTfa);
        User::factory()->staff()->create(['name' => 'Ina Inactive', 'is_active' => false]);
        $client = $this->customer();

        $page = $this->actingAs($admin)->get(route('admin.staff.index'))->assertOk()
            ->assertSee($admin->name)->assertSee('Tina Twofactor')->assertSee('Ina Inactive')->assertSee('Administrator')->assertSee('Inactive')->assertSee('1 hour ago')->assertSee('Reset 2FA');
        $page->assertDontSee($client->email);
    }

    public function test_create_with_a_password_or_a_link(): void
    {
        Notification::fake();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.staff.store'), ['name' => 'Sam Staff', 'email' => 'sam@example.com', 'role' => 'staff', 'password_mode' => 'set', 'password' => 'a-good-password'])->assertRedirect(route('admin.staff.index'));
        $sam = User::where('email', 'sam@example.com')->firstOrFail();
        $this->assertSame(Role::Staff, $sam->role);
        $this->assertTrue($sam->is_active);
        $this->assertTrue(\Hash::check('a-good-password', $sam->password));
        Notification::assertNothingSent();

        $this->actingAs($admin)->post(route('admin.staff.store'), ['name' => 'Ada Admin', 'email' => 'ada@example.com', 'role' => 'admin', 'password_mode' => 'link'])->assertRedirect(route('admin.staff.index'));
        $ada = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertSame(Role::Admin, $ada->role);
        Notification::assertSentTo($ada, ResetPassword::class);
        $this->assertDatabaseHas('activity_logs', ['action' => 'staff.created', 'subject_id' => $sam->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'staff.created', 'subject_id' => $ada->id]);
    }

    public function test_create_validation(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.staff.store'), ['name' => 'A', 'email' => $admin->email, 'role' => 'customer', 'password_mode' => 'set', 'password' => 'short'])->assertSessionHasErrors(['email', 'role', 'password']);
        $this->actingAs($admin)->post(route('admin.staff.store'), ['name' => 'A', 'email' => 'a@example.com', 'role' => 'staff', 'password_mode' => 'set'])->assertSessionHasErrors('password');
        $this->assertSame(1, User::count());
    }

    public function test_change_role_and_last_administrator_protection(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->staff()->create();

        $this->actingAs($admin)->put(route('admin.staff.update', $staff), ['name' => $staff->name, 'email' => $staff->email, 'role' => 'admin'])->assertRedirect(route('admin.staff.index'));
        $this->assertSame(Role::Admin, $staff->fresh()->role);
        $this->assertDatabaseHas('activity_logs', ['action' => 'staff.role_changed', 'subject_id' => $staff->id]);

        // two admins: one may be demoted, even the actor themselves
        $this->actingAs($admin)->put(route('admin.staff.update', $admin), ['name' => $admin->name, 'email' => $admin->email, 'role' => 'staff'])->assertRedirect(route('admin.staff.index'));
        $this->assertSame(Role::Staff, $admin->fresh()->role);

        // the last one may not (staff is now the only administrator)
        $this->actingAs($staff->fresh())->put(route('admin.staff.update', $staff), ['name' => $staff->name, 'email' => $staff->email, 'role' => 'staff'])->assertSessionHas('error');
        $this->assertSame(Role::Admin, $staff->fresh()->role);

        // an inactive admin does not count as an active administrator
        $admin->forceFill(['role' => Role::Admin, 'is_active' => false])->save();
        $this->actingAs($staff->fresh())->put(route('admin.staff.update', $staff), ['name' => $staff->name, 'email' => $staff->email, 'role' => 'staff'])->assertSessionHas('error');
        $this->assertSame(Role::Admin, $staff->fresh()->role);
    }

    public function test_cannot_edit_a_client_login_through_staff_management(): void
    {
        $admin = $this->admin();
        $client = $this->customer();
        $this->actingAs($admin)->get(route('admin.staff.edit', $client))->assertNotFound();
        $this->actingAs($admin)->put(route('admin.staff.update', $client), ['name' => 'x', 'email' => 'x@example.com', 'role' => 'admin'])->assertNotFound();
        $this->actingAs($admin)->post(route('admin.staff.deactivate', $client))->assertNotFound();
        $this->assertSame(Role::Customer, $client->fresh()->role);
    }

    public function test_deactivate_revokes_tokens_and_devices_and_blocks_login(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->staff()->create();
        $this->enrol($staff);
        ApiToken::issue($staff, 'one');
        ApiToken::issue($staff, 'two');
        app(TwoFactor::class)->deviceCookie($staff->fresh());

        $this->actingAs($admin)->post(route('admin.staff.deactivate', $staff))->assertSessionHas('status');
        $this->assertFalse($staff->fresh()->is_active);
        $this->assertSame(0, $staff->apiTokens()->count());
        $this->assertSame(0, $staff->twoFactorDevices()->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'staff.deactivated', 'subject_id' => $staff->id]);
        auth()->logout();
        $this->flushSession();
        $this->post('/login', ['email' => $staff->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->actingAs($admin);

        $this->actingAs($admin)->post(route('admin.staff.activate', $staff))->assertSessionHas('status');
        $this->assertTrue($staff->fresh()->is_active);
        $this->assertDatabaseHas('activity_logs', ['action' => 'staff.activated', 'subject_id' => $staff->id]);
    }

    public function test_deactivation_ends_database_sessions(): void
    {
        config(['session.driver' => 'database']);
        \DB::table('sessions')->insert(['id' => 's1', 'user_id' => 0, 'payload' => '', 'last_activity' => time()]);
        $admin = $this->admin();
        $staff = User::factory()->staff()->create();
        \DB::table('sessions')->where('id', 's1')->update(['user_id' => $staff->id]);

        $this->actingAs($admin)->post(route('admin.staff.deactivate', $staff));
        $this->assertSame(0, \DB::table('sessions')->where('user_id', $staff->id)->count());
    }

    public function test_nobody_can_deactivate_themselves_or_the_last_administrator(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.staff.deactivate', $admin))->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->is_active);

        // an administrator cannot remove the only other admin's... only themselves: with a second admin, deactivating that one works
        $other = $this->admin();
        $this->actingAs($admin)->post(route('admin.staff.deactivate', $other))->assertSessionHas('status');
        // and now $admin is the last active administrator: still cannot self-deactivate
        $this->actingAs($admin)->post(route('admin.staff.deactivate', $admin))->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->is_active);
        // the last-administrator rule also holds for the service itself, whoever asks
        $this->expectException(\DomainException::class);
        app(StaffAccounts::class)->setActive(User::factory()->staff()->create(), $admin->fresh(), false);
    }

    public function test_send_password_reset_link(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $staff = User::factory()->staff()->create();
        $this->actingAs($admin)->post(route('admin.staff.password-reset-link', $staff))->assertSessionHas('status');
        Notification::assertSentTo($staff, ResetPassword::class);
        $this->assertDatabaseHas('activity_logs', ['action' => 'staff.reset_link_sent', 'subject_id' => $staff->id]);
    }

    public function test_reset_two_factor_from_the_staff_page_follows_existing_rules(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->staff()->create();
        $other = User::factory()->staff()->create();
        $this->enrol($staff);
        $this->enrol($admin);

        // non-admin staff cannot reset staff
        $this->actingAs($other)->post(route('admin.users.two-factor.reset', $staff))->assertForbidden();
        // nobody resets their own
        $this->actingAs($admin)->post(route('admin.users.two-factor.reset', $admin))->assertForbidden();
        $this->assertTrue($admin->fresh()->hasTwoFactor());
        // the administrator can
        $this->actingAs($admin)->post(route('admin.users.two-factor.reset', $staff))->assertSessionHas('status');
        $this->assertFalse($staff->fresh()->hasTwoFactor());
        $this->assertDatabaseHas('activity_logs', ['action' => 'two_factor.reset', 'subject_id' => $staff->id]);
    }

    public function test_staff_page_offers_reset_only_for_other_users_with_2fa(): void
    {
        $admin = $this->admin();
        $this->enrol($admin);
        $page = $this->actingAs($admin)->get(route('admin.staff.index'))->assertOk();
        $page->assertDontSee('Reset 2FA')->assertDontSee('Deactivate'); // only yourself on the list
        $this->assertStringContainsString('pill good">On', $page->getContent());
    }

    public function test_a_deactivated_user_is_signed_out_on_their_next_request(): void
    {
        $staff = User::factory()->staff()->create();
        $this->actingAs($staff)->get(route('admin.dashboard'))->assertOk();

        $staff->forceFill(['is_active' => false])->save();

        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
