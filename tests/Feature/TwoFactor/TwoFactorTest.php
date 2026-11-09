<?php

namespace Tests\Feature\TwoFactor;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\TwoFactor;
use App\Support\Settings;
use App\Support\TwoFactor\Base32;
use App\Support\TwoFactor\Totp;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    /** Enrol a user directly and return [secret, recoveryCodes]. */
    private function enrol(User $user): array
    {
        $svc = app(TwoFactor::class);
        $secret = $svc->beginEnrollment($user);
        $codes = $svc->confirmEnrollment($user, Totp::code($secret, Totp::timestep()));

        return [$secret, $codes];
    }

    /** A code for a step later than any used so far (the service refuses replays of the same step). */
    private function nextCode(string $secret, int $ahead = 1): string
    {
        return Totp::code($secret, Totp::timestep() + $ahead);
    }

    public function test_rfc_vectors(): void
    {
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', Base32::encode('12345678901234567890'));
        $this->assertSame('12345678901234567890', Base32::decode('gezd gnbv-gy3t qojq gezd gnbv gy3t qojq'));
        $secret = Base32::encode('12345678901234567890');
        // RFC 6238 appendix B (SHA1), last 6 digits of the 8 digit values.
        $this->assertSame('287082', Totp::code($secret, intdiv(59, 30)));
        $this->assertSame('081804', Totp::code($secret, intdiv(1111111109, 30)));
        $this->assertSame('050471', Totp::code($secret, intdiv(1111111111, 30)));
        $this->assertSame(intdiv(59, 30), Totp::verify($secret, '287082', 59));
        $this->assertSame(intdiv(59, 30), Totp::verify($secret, '287082', 59 + 30)); // +/- 1 window
        $this->assertNull(Totp::verify($secret, '287082', 59 + 90));
    }

    public function test_customer_enrols_confirms_and_sees_recovery_codes_once(): void
    {
        $user = $this->customer();
        $this->actingAs($user)->get(route('portal.security.index'))->assertOk()->assertSee('Set up');

        $this->actingAs($user)->post(route('portal.security.start'))->assertRedirect(route('portal.security.index'));
        $user->refresh();
        $this->assertFalse($user->hasTwoFactor());
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNotSame($user->two_factor_secret, $user->getRawOriginal('two_factor_secret')); // encrypted at rest

        $page = $this->actingAs($user)->get(route('portal.security.index'))->assertOk()->assertSee('<svg', false)->assertSee('otpauth://totp/', false);
        $this->assertStringContainsString(rtrim(chunk_split($user->two_factor_secret, 4, ' ')), $page->getContent());

        // wrong code
        $this->actingAs($user)->post(route('portal.security.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->hasTwoFactor());

        // right code
        $this->actingAs($user)->post(route('portal.security.confirm'), ['code' => Totp::code($user->two_factor_secret, Totp::timestep())])
            ->assertRedirect(route('portal.security.index'))->assertSessionHas('two_factor_codes');
        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->assertCount(10, json_decode($user->fresh()->getRawOriginal('two_factor_recovery_codes'), true));
        $this->assertDatabaseHas('activity_logs', ['action' => 'two_factor.enabled']);

        $this->actingAs($user)->get(route('portal.security.index'))->assertSee('Save your recovery codes'); // shown once
        $this->actingAs($user)->get(route('portal.security.index'))->assertDontSee('Save your recovery codes');
    }

    public function test_recovery_codes_are_stored_hashed(): void
    {
        $user = $this->customer();
        [, $codes] = $this->enrol($user);
        $stored = $user->fresh()->getRawOriginal('two_factor_recovery_codes');
        foreach ($codes as $c) {
            $this->assertStringNotContainsString($c, $stored);
        }
    }

    public function test_totp_replay_is_refused(): void
    {
        $user = $this->customer();
        [$secret] = $this->enrol($user);
        $svc = app(TwoFactor::class);
        $code = $this->nextCode($secret);
        $this->assertTrue($svc->verify($user->fresh(), $code));
        $this->assertFalse($svc->verify($user->fresh(), $code));
        $this->assertFalse($svc->verify($user->fresh(), 'garbage'));
    }

    public function test_login_requires_challenge_then_signs_in(): void
    {
        $user = $this->customer();
        [$secret] = $this->enrol($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->get(route('portal.dashboard'))->assertRedirect(route('login'));

        $this->get(route('two-factor.challenge'))->assertOk()->assertSee('Authentication code');
        $this->post(route('two-factor.verify'), ['code' => '123456'])->assertRedirect(route('two-factor.challenge'))->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post(route('two-factor.verify'), ['code' => $this->nextCode($secret)])->assertRedirect(route('portal.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_never_reaches_challenge(): void
    {
        $user = $this->customer();
        $this->enrol($user);
        $this->post('/login', ['email' => $user->email, 'password' => 'nope'])->assertSessionHasErrors('email');
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }

    public function test_challenge_expires_after_five_minutes(): void
    {
        $user = $this->customer();
        [$secret] = $this->enrol($user);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->travel(6)->minutes();
        $this->post(route('two-factor.verify'), ['code' => Totp::code($secret, Totp::timestep())])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_challenge_attempts_are_throttled(): void
    {
        $user = $this->customer();
        [$secret] = $this->enrol($user);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('two-factor.verify'), ['code' => '111111'])->assertSessionHasErrors('code');
        }
        // even a correct code is refused while throttled
        $this->post(route('two-factor.verify'), ['code' => $this->nextCode($secret)])->assertSessionHasErrors('code');
        $this->assertGuest();
        RateLimiter::clear('two-factor:'.$user->id.'|127.0.0.1');
    }

    public function test_recovery_code_works_once(): void
    {
        $user = $this->customer();
        [, $codes] = $this->enrol($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->get(route('two-factor.challenge', ['recovery' => 1]))->assertOk()->assertSee('Recovery code');
        $this->post(route('two-factor.verify'), ['code' => $codes[0], 'recovery' => 1])->assertRedirect(route('portal.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('activity_logs', ['action' => 'two_factor.recovery_code_used']);
        $this->assertSame(9, app(TwoFactor::class)->remainingRecoveryCodes($user->fresh()));

        auth()->logout();
        $this->flushSession();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.verify'), ['code' => $codes[0], 'recovery' => 1])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_remember_device_skips_challenge_until_password_changes(): void
    {
        $user = $this->customer();
        [$secret] = $this->enrol($user);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $res = $this->post(route('two-factor.verify'), ['code' => $this->nextCode($secret), 'remember_device' => 1]);
        $cookie = collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === TwoFactor::DEVICE_COOKIE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $value = CookieValuePrefix::remove(app('encrypter')->decrypt($cookie->getValue(), false));
        $this->assertStringNotContainsString($secret, $value);

        $svc = app(TwoFactor::class);
        $this->assertTrue($svc->deviceTrusted($user->fresh(), $value));
        $this->assertFalse($svc->deviceTrusted($user->fresh(), $value.'x'));

        // password change kills it
        $user->update(['password' => 'a-new-password']);
        $this->assertFalse($svc->deviceTrusted($user->fresh(), $value));

        // and so does reset
        $user->update(['password' => 'password']);
        $fresh = $user->fresh();
        $cookieNow = $svc->deviceCookie($fresh)->getValue();
        $this->assertTrue($svc->deviceTrusted($fresh, $cookieNow));
        $svc->reset($fresh);
        $this->assertFalse($svc->deviceTrusted($fresh->fresh(), $cookieNow));
    }

    public function test_trusted_device_cookie_bypasses_challenge_on_login(): void
    {
        $user = $this->customer();
        [$secret] = $this->enrol($user);
        $cookie = app(TwoFactor::class)->deviceCookie($user->fresh())->getValue();

        $this->withCookie(TwoFactor::DEVICE_COOKIE, $cookie)->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('portal.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_disable_and_new_codes_need_password_and_code(): void
    {
        $user = $this->customer();
        [$secret] = $this->enrol($user);

        $this->actingAs($user)->post(route('portal.security.recovery-codes'), ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->actingAs($user)->post(route('portal.security.recovery-codes'), ['current_password' => 'password'])->assertSessionHas('two_factor_codes');

        $this->actingAs($user)->delete(route('portal.security.disable'), ['current_password' => 'wrong', 'code' => $this->nextCode($secret)])->assertSessionHasErrors('current_password');
        $this->actingAs($user)->delete(route('portal.security.disable'), ['current_password' => 'password', 'code' => '000000'])->assertSessionHasErrors('code');
        $this->assertTrue($user->fresh()->hasTwoFactor());

        $this->actingAs($user)->delete(route('portal.security.disable'), ['current_password' => 'password', 'code' => $this->nextCode($secret, 1)])->assertSessionHas('status');
        $user = $user->fresh();
        $this->assertFalse($user->hasTwoFactor());
        $this->assertNull($user->getRawOriginal('two_factor_secret'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'two_factor.disabled']);
    }

    public function test_staff_enrol_from_admin_security_page(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.account.security.index'))->assertOk()->assertSee('Security');
        $this->actingAs($admin)->post(route('admin.account.security.start'))->assertRedirect();
        $admin->refresh();
        $this->actingAs($admin)->post(route('admin.account.security.confirm'), ['code' => Totp::code($admin->two_factor_secret, Totp::timestep())])->assertSessionHas('two_factor_codes');
        $this->assertTrue($admin->fresh()->hasTwoFactor());
    }

    public function test_admin_can_reset_a_client_contact(): void
    {
        $customer = $this->customer();
        $this->enrol($customer);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.clients.show', $customer->client))->assertOk()->assertSee(route('admin.users.two-factor.reset', $customer), false);
        $this->actingAs($admin)->post(route('admin.users.two-factor.reset', $customer))->assertSessionHas('status');
        $this->assertFalse($customer->fresh()->hasTwoFactor());
        $this->assertDatabaseHas('activity_logs', ['action' => 'two_factor.reset', 'user_id' => $admin->id]);

        // customers cannot use it, and staff cannot reset admins
        $this->actingAs($customer)->post(route('admin.users.two-factor.reset', $admin))->assertForbidden();
        $staff = User::factory()->staff()->create();
        $this->actingAs($staff)->post(route('admin.users.two-factor.reset', $admin))->assertForbidden();
    }

    public function test_require_staff_setting_redirects_unenrolled_staff(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

        $this->actingAs($admin)->put(route('admin.settings.update', 'security'), ['s' => ['security__require_staff_2fa' => '1']])->assertRedirect(route('admin.settings.index', 'security'));
        $this->assertTrue(Settings::bool('security.require_staff_2fa'));
        $this->actingAs($admin)->get(route('admin.settings.index', 'security'))->assertRedirect(route('admin.account.security.index'));

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertRedirect(route('admin.account.security.index'));
        $this->actingAs($admin)->get(route('admin.account.security.index'))->assertOk();

        $this->enrol($admin);
        $this->actingAs($admin->fresh())->get(route('admin.dashboard'))->assertOk();
        // required staff cannot switch it off
        $this->actingAs($admin->fresh())->get(route('admin.account.security.index'))->assertDontSee('Turn off two-factor');
        // customers are unaffected
        $this->actingAs($this->customer())->get(route('portal.dashboard'))->assertOk();
    }

    public function test_impersonation_does_not_require_customer_code_and_cannot_change_it(): void
    {
        $customer = $this->customer();
        $this->enrol($customer);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.impersonate.start', $customer))->assertRedirect(route('portal.dashboard'));
        $this->get(route('portal.dashboard'))->assertOk();
        $this->post(route('portal.security.recovery-codes'), ['current_password' => 'password'])->assertForbidden();
    }

    public function test_reset_command(): void
    {
        $user = $this->customer();
        $this->enrol($user);
        $this->artisan('billing:reset-2fa', ['email' => $user->email])->assertSuccessful();
        $this->assertFalse($user->fresh()->hasTwoFactor());
        $this->artisan('billing:reset-2fa', ['email' => 'nobody@example.com'])->assertFailed();
        $this->assertTrue(ActivityLog::where('action', 'two_factor.reset')->exists());
    }
}
