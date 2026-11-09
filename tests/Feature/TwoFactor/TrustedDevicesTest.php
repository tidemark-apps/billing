<?php

namespace Tests\Feature\TwoFactor;

use App\Models\TwoFactorDevice;
use App\Models\User;
use App\Services\TwoFactor;
use App\Support\TwoFactor\Totp;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Remember this device": server-side rows, listing and per-device revocation. */
class TrustedDevicesTest extends TestCase
{
    use RefreshDatabase;

    private function enrol(User $user): string
    {
        $svc = app(TwoFactor::class);
        $secret = $svc->beginEnrollment($user);
        $svc->confirmEnrollment($user, Totp::code($secret, Totp::timestep()));

        return $secret;
    }

    private function remember(User $user, string $agent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15'): string
    {
        return app(TwoFactor::class)->deviceCookie($user->fresh(), request()->duplicate(server: ['HTTP_USER_AGENT' => $agent, 'REMOTE_ADDR' => '203.0.113.9']))->getValue();
    }

    public function test_remembering_stores_a_hashed_token_and_a_description(): void
    {
        $user = $this->customer();
        $secret = $this->enrol($user);
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:120.0) Gecko/20100101 Firefox/120.0')
            ->post('/login', ['email' => $user->email, 'password' => 'password']);
        $res = $this->post(route('two-factor.verify'), ['code' => Totp::code($secret, Totp::timestep() + 1), 'remember_device' => 1]);
        $cookie = collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === TwoFactor::DEVICE_COOKIE);

        $this->assertTrue($cookie->isHttpOnly());
        $token = CookieValuePrefix::remove(app('encrypter')->decrypt($cookie->getValue(), false));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{48}$/', $token);
        $device = TwoFactorDevice::firstOrFail();
        $this->assertSame(hash('sha256', $token), $device->token_hash);
        $this->assertNotSame($token, $device->token_hash);
        $this->assertSame('Firefox on Windows', $device->label);
        $this->assertSame($user->id, $device->user_id);
        $this->assertTrue($device->expires_at->between(now()->addDays(29), now()->addDays(31)));
    }

    public function test_cookie_of_the_old_scheme_is_no_longer_valid(): void
    {
        $user = $this->customer();
        $this->enrol($user);
        $expires = now()->addDays(10)->getTimestamp();
        $old = $user->id.'|'.$expires.'|'.hash_hmac('sha256', 'whatever', 'key');

        $this->assertFalse(app(TwoFactor::class)->deviceTrusted($user->fresh(), $old));
        $this->withCookie(TwoFactor::DEVICE_COOKIE, $old)->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
    }

    public function test_security_pages_list_devices_for_customers_and_staff_and_mark_the_current_one(): void
    {
        foreach ([[$this->customer(), 'portal.security.index'], [$this->admin(), 'admin.account.security.index']] as [$user, $route]) {
            $this->enrol($user);
            $mine = $this->remember($user);
            $this->remember($user, 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36');

            $page = $this->actingAs($user)->withCookie(TwoFactor::DEVICE_COOKIE, $mine)->get(route($route))->assertOk()
                ->assertSee('Trusted devices')->assertSee('Safari on macOS')->assertSee('Chrome on Linux')->assertSee('Revoke all')->assertSee('203.0.113.9');
            $this->assertSame(1, substr_count($page->getContent(), 'This device'));
        }
        $this->assertSame(4, TwoFactorDevice::count());
    }

    public function test_a_single_device_can_be_revoked_and_stops_working(): void
    {
        $user = $this->customer();
        $this->enrol($user);
        $a = $this->remember($user);
        $b = $this->remember($user, 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0 Safari/537.36');
        $svc = app(TwoFactor::class);
        $deviceB = $svc->currentDevice($user->fresh(), $b);

        $this->actingAs($user)->withCookie(TwoFactor::DEVICE_COOKIE, $a)->delete(route('portal.security.devices.destroy', $deviceB->id))->assertRedirect(route('portal.security.index'))->assertSessionHas('status');
        $this->assertFalse($svc->deviceTrusted($user->fresh(), $b));
        $this->assertTrue($svc->deviceTrusted($user->fresh(), $a));
        $this->assertDatabaseHas('activity_logs', ['action' => 'two_factor.device_revoked']);

        // revoking the current device also drops its cookie
        $current = $svc->currentDevice($user->fresh(), $a);
        $res = $this->actingAs($user)->withCookie(TwoFactor::DEVICE_COOKIE, $a)->delete(route('portal.security.devices.destroy', $current->id));
        $forgotten = collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === TwoFactor::DEVICE_COOKIE);
        $this->assertNotNull($forgotten);
        $this->assertTrue($forgotten->getExpiresTime() < time());
        $this->assertSame(0, $user->twoFactorDevices()->count());
    }

    public function test_nobody_can_revoke_someone_elses_device(): void
    {
        $owner = $this->customer();
        $this->enrol($owner);
        $this->remember($owner);
        $other = $this->customer();
        $id = TwoFactorDevice::firstOrFail()->id;

        $this->actingAs($other)->delete(route('portal.security.devices.destroy', $id))->assertNotFound();
        $this->assertSame(1, TwoFactorDevice::count());
    }

    public function test_revoke_all_clears_every_device_of_the_user_only(): void
    {
        $user = $this->admin();
        $this->enrol($user);
        $this->remember($user);
        $this->remember($user);
        $other = $this->customer();
        $this->enrol($other);
        $this->remember($other);

        $this->actingAs($user)->delete(route('admin.account.security.devices.destroy-all'))->assertRedirect(route('admin.account.security.index'));
        $this->assertSame(0, $user->twoFactorDevices()->count());
        $this->assertSame(1, $other->twoFactorDevices()->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'two_factor.devices_revoked']);
    }

    public function test_expired_devices_are_refused_and_pruned(): void
    {
        $user = $this->customer();
        $this->enrol($user);
        $cookie = $this->remember($user);
        TwoFactorDevice::query()->update(['expires_at' => now()->subMinute()]);

        $this->assertFalse(app(TwoFactor::class)->deviceTrusted($user->fresh(), $cookie));
        $this->assertSame(0, TwoFactorDevice::count());

        // listing and adding prune other expired rows
        $this->remember($user);
        TwoFactorDevice::query()->update(['expires_at' => now()->subDay()]);
        $this->assertCount(0, app(TwoFactor::class)->devices($user->fresh()));
        $this->assertSame(0, TwoFactorDevice::count());
        TwoFactorDevice::create(['user_id' => $user->id, 'token_hash' => str_repeat('a', 64), 'binding' => 'x', 'expires_at' => now()->subDay()]);
        $this->remember($user);
        $this->assertSame(1, TwoFactorDevice::count());
    }

    public function test_password_change_reset_and_disable_clear_devices(): void
    {
        $svc = app(TwoFactor::class);
        $user = $this->customer();
        $secret = $this->enrol($user);
        $cookie = $this->remember($user);
        $this->assertTrue($svc->deviceTrusted($user->fresh(), $cookie));

        $user->update(['password' => 'a-brand-new-password']);
        $this->assertFalse($svc->deviceTrusted($user->fresh(), $cookie));
        $this->assertSame(0, TwoFactorDevice::count());

        $user->update(['password' => 'password']);
        $this->remember($user);
        $svc->reset($user->fresh());
        $this->assertSame(0, TwoFactorDevice::count());

        // disabling through the security page
        $user = $this->customer();
        $secret = $this->enrol($user);
        $this->remember($user);
        $this->actingAs($user)->delete(route('portal.security.disable'), ['current_password' => 'password', 'code' => Totp::code($secret, Totp::timestep() + 1)])->assertSessionHasNoErrors();
        $this->assertSame(0, $user->twoFactorDevices()->count());
    }

    public function test_devices_cannot_be_changed_while_viewing_as_client(): void
    {
        $user = $this->customer();
        $this->enrol($user);
        $this->remember($user);
        $staff = $this->admin();

        $this->actingAs($user)->withSession(['impersonator_id' => $staff->id])->delete(route('portal.security.devices.destroy-all'))->assertForbidden();
        $this->assertSame(1, $user->twoFactorDevices()->count());
    }
}
