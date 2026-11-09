<?php

namespace Tests\Feature\Api;

use App\Models\ActivityLog;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\TwoFactor;
use App\Support\Settings;
use App\Support\TwoFactor\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** POST /auth/token with two-factor authentication: otp_required, invalid_otp, replays, recovery codes, throttling, staff enrolment. */
class AuthTwoFactorTest extends TestCase
{
    use ApiTestHelpers, RefreshDatabase;

    /** @return array{0: string, 1: array<int, string>} secret and recovery codes */
    private function enrol(User $user): array
    {
        $svc = app(TwoFactor::class);
        $secret = $svc->beginEnrollment($user);
        $codes = $svc->confirmEnrollment($user, Totp::code($secret, Totp::timestep()));

        return [$secret, $codes];
    }

    private function login(User $user, array $extra = [])
    {
        $this->flushHeaders();

        return $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'password'] + $extra);
    }

    public function test_users_without_two_factor_are_unaffected(): void
    {
        $this->login($this->customer())->assertCreated();
        $this->login($this->customer(), ['otp' => '123456'])->assertCreated(); // an unneeded otp is ignored
    }

    public function test_an_otp_is_required_and_a_wrong_one_is_refused(): void
    {
        $user = $this->customer();
        [$secret] = $this->enrol($user);

        $this->login($user)->assertStatus(401)->assertJsonPath('error.code', 'otp_required');
        $this->login($user, ['otp' => ''])->assertStatus(401)->assertJsonPath('error.code', 'otp_required');
        $this->login($user, ['otp' => '000000'])->assertStatus(401)->assertJsonPath('error.code', 'invalid_otp');
        $this->login($user, ['otp' => 123456])->assertStatus(422); // send it as a string
        $this->assertSame(0, ApiToken::where('user_id', $user->id)->count());

        $this->login($user, ['otp' => Totp::code($secret, Totp::timestep() + 1)])->assertCreated()->assertJsonStructure(['data' => ['token']]);
        $this->assertSame(1, ApiToken::where('user_id', $user->id)->count());
    }

    public function test_a_wrong_password_is_still_invalid_credentials_and_never_hints_at_two_factor(): void
    {
        $user = $this->customer();
        $this->enrol($user);

        $this->flushHeaders()->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'nope', 'otp' => '123456'])->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_a_code_cannot_be_replayed(): void
    {
        $user = $this->customer();
        [$secret] = $this->enrol($user);
        $code = Totp::code($secret, Totp::timestep() + 1);

        $this->login($user, ['otp' => $code])->assertCreated();
        $this->login($user, ['otp' => $code])->assertStatus(401)->assertJsonPath('error.code', 'invalid_otp');
    }

    public function test_a_recovery_code_works_once_and_is_logged(): void
    {
        $user = $this->customer();
        [, $codes] = $this->enrol($user);

        $this->login($user, ['otp' => $codes[0]])->assertCreated();
        $this->login($user, ['otp' => $codes[0]])->assertStatus(401)->assertJsonPath('error.code', 'invalid_otp');
        $this->login($user, ['otp' => $codes[1]])->assertCreated();
        $this->assertSame(2, ActivityLog::where('action', 'two_factor.recovery_code_used')->count());
    }

    public function test_attempts_are_throttled_per_user_and_ip(): void
    {
        $user = $this->customer();
        $other = $this->customer();
        [$secret] = $this->enrol($user);
        RateLimiter::clear('two-factor-api:'.$user->id.'|127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            $this->login($user, ['otp' => '000000'])->assertStatus(401)->assertJsonPath('error.code', 'invalid_otp');
        }
        // even the right code is refused while locked out
        $this->login($user, ['otp' => Totp::code($secret, Totp::timestep() + 1)])->assertStatus(429)->assertJsonPath('error.code', 'too_many_requests')->assertHeader('Retry-After');
        $this->assertSame(0, ApiToken::where('user_id', $user->id)->count());
        // a missing code is not an attempt, and other users are unaffected
        $this->login($other)->assertCreated();
    }

    public function test_a_successful_login_resets_the_attempt_counter(): void
    {
        $user = $this->customer();
        [$secret] = $this->enrol($user);
        for ($i = 0; $i < 4; $i++) {
            $this->login($user, ['otp' => '000000'])->assertStatus(401);
        }
        $this->login($user, ['otp' => Totp::code($secret, Totp::timestep() + 1)])->assertCreated();
        $this->assertSame(0, RateLimiter::attempts('two-factor-api:'.$user->id.'|127.0.0.1'));
    }

    public function test_the_api_never_sets_a_device_cookie(): void
    {
        $user = $this->customer();
        [$secret] = $this->enrol($user);

        $res = $this->login($user, ['otp' => Totp::code($secret, Totp::timestep() + 1), 'remember_device' => 1])->assertCreated();
        $this->assertNull($res->getCookie(TwoFactor::DEVICE_COOKIE));
        // a trusted-device cookie sent by a browser does not replace the otp either
        $this->flushHeaders()->withCookie(TwoFactor::DEVICE_COOKIE, app(TwoFactor::class)->deviceCookie($user)->getValue())
            ->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'password'])->assertStatus(401)->assertJsonPath('error.code', 'otp_required');
    }

    public function test_staff_with_two_factor_need_an_otp_too_and_get_a_staff_token(): void
    {
        $staff = $this->staffUser();
        [$secret] = $this->enrol($staff);

        $this->login($staff)->assertStatus(401)->assertJsonPath('error.code', 'otp_required');
        $token = $this->login($staff, ['otp' => Totp::code($secret, Totp::timestep() + 1)])->assertCreated()->json('data.token');
        $this->flushHeaders()->withToken($token)->getJson('/api/v1/admin/me')->assertOk();
    }

    public function test_required_staff_two_factor_blocks_tokens_until_enrolled(): void
    {
        $staff = $this->staffUser();
        $customer = $this->customer();
        Settings::set('security.require_staff_2fa', 'true');

        $this->login($staff)->assertStatus(403)->assertJsonPath('error.code', 'two_factor_enrolment_required');
        $this->login($staff, ['otp' => '123456'])->assertStatus(403)->assertJsonPath('error.code', 'two_factor_enrolment_required');
        $this->login($customer)->assertCreated(); // the rule is for staff only
        $this->assertSame(0, ApiToken::where('user_id', $staff->id)->count());

        [$secret] = $this->enrol($staff);
        $this->login($staff, ['otp' => Totp::code($secret, Totp::timestep() + 1)])->assertCreated();

        // wrong passwords still do not reveal the policy
        $other = $this->staffUser();
        $this->flushHeaders()->postJson('/api/v1/auth/token', ['email' => $other->email, 'password' => 'bad'])->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_unknown_abilities_are_rejected_before_the_code_is_spent(): void
    {
        $user = $this->customer();
        [$secret] = $this->enrol($user);
        $code = Totp::code($secret, Totp::timestep() + 1);

        $this->login($user, ['otp' => $code, 'abilities' => ['clients.write']])->assertStatus(422);
        $this->login($user, ['otp' => $code, 'abilities' => ['invoices.read']])->assertCreated();
    }
}
