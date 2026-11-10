<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\HostToken;
use App\Services\Registration;
use App\Services\TwoFactor;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $key = 'login:'.Str::lower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }
        $credentials = ['email' => $data['email'], 'password' => $data['password'], 'is_active' => true];
        $provider = Auth::guard()->getProvider();
        $user = $provider->retrieveByCredentials($credentials);
        if (! $user || ! $provider->validateCredentials($user, $credentials)) {
            RateLimiter::hit($key);
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        RateLimiter::clear($key);

        // Second factor: the user is NOT signed in yet. Park the id in the session for a few minutes and ask for a code.
        if ($user->hasTwoFactor() && ! app(TwoFactor::class)->deviceTrusted($user, $request->cookie(TwoFactor::DEVICE_COOKIE))) {
            $request->session()->put('two_factor', ['id' => $user->id, 'expires' => now()->addMinutes(5)->getTimestamp(), 'remember' => $request->boolean('remember')]);

            return redirect()->route('two-factor.challenge');
        }

        return $this->finishLogin($request, $user, $request->boolean('remember'));
    }

    /** One-time sign-in link made by `php artisan billing:login-link` (signed, expiring, usable once). Two-factor still applies. */
    public function loginLink(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->hasValidSignature() && $user->is_active, 403, 'This sign-in link is invalid or has expired.');
        abort_unless(Cache::add('login-link:'.$request->query('signature'), 1, now()->addDay()), 403, 'This sign-in link has already been used.');

        if ($user->hasTwoFactor()) {
            $request->session()->put('two_factor', ['id' => $user->id, 'expires' => now()->addMinutes(5)->getTimestamp(), 'remember' => false]);

            return redirect()->route('two-factor.challenge');
        }
        Activity::log('auth.login_link', $user, "{$user->email} signed in with a one-time link");

        return $this->finishLogin($request, $user, false);
    }

    /**
     * Sign-in link minted by the host (see HostToken). "customer" signs in the owner; "support" signs in as an administrator for a
     * support session: the staff member, reason and ticket are written to the activity log and a banner shows for the whole session.
     */
    public function sso(Request $request): RedirectResponse
    {
        $claims = HostToken::verify((string) $request->query('token'), $request->getHost());
        abort_unless($claims, 403, 'This sign-in link is invalid, expired or already used.');

        $email = is_string($claims['email'] ?? null) && $claims['email'] !== '' ? $claims['email'] : null;
        $user = User::query()->where('is_active', true)->where('role', Role::Admin)
            ->when($email, fn ($q) => $q->where('email', $email))->orderBy('id')->first();
        abort_unless($user, 403, 'No administrator account matches this sign-in link.');

        if ($claims['kind'] === 'support') {
            $who = is_array($claims['staff'] ?? null) ? (string) ($claims['staff']['name'] ?? 'Support') : 'Support';
            Activity::log('auth.support_session', $user, "$who started a support session as {$user->email}", ['staff' => $claims['staff'] ?? null, 'reason' => $claims['reason'] ?? null, 'ticket' => $claims['ticket'] ?? null]);
            $response = $this->finishLogin($request, $user, false, home: true);
            $request->session()->put('support_session', $who);

            return $response;
        }
        if ($user->hasTwoFactor()) {
            $request->session()->put('two_factor', ['id' => $user->id, 'expires' => now()->addMinutes(5)->getTimestamp(), 'remember' => false]);

            return redirect()->route('two-factor.challenge');
        }
        Activity::log('auth.sso', $user, "{$user->email} signed in from the host's account page");

        return $this->finishLogin($request, $user, false, home: true);
    }

    public function showChallenge(Request $request)
    {
        $user = $this->pendingTwoFactorUser($request);
        if (! $user) {
            return redirect()->route('login')->with('error', 'Your sign-in expired. Please sign in again.');
        }

        return view('auth.two-factor', ['recovery' => $request->boolean('recovery')]);
    }

    public function challenge(Request $request): RedirectResponse
    {
        $user = $this->pendingTwoFactorUser($request);
        if (! $user) {
            return redirect()->route('login')->with('error', 'Your sign-in expired. Please sign in again.');
        }
        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);
        $key = 'two-factor:'.$user->id.'|'.$request->ip();
        $field = 'code';

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([$field => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }
        $twoFactor = app(TwoFactor::class);
        $usedRecovery = ! preg_match('/^\d{6}$/', preg_replace('/[\s-]/', '', $data['code']) ?? '');
        if (! $twoFactor->verify($user, $data['code'])) {
            RateLimiter::hit($key, 300);

            return redirect()->route('two-factor.challenge', $request->boolean('recovery') ? ['recovery' => 1] : [])->withErrors([$field => $usedRecovery ? 'That recovery code is not valid or was already used.' : 'That code is not valid. Check the time on your phone and try again.']);
        }
        RateLimiter::clear($key);
        $remember = (bool) ($request->session()->get('two_factor.remember'));
        $request->session()->forget('two_factor');
        if ($usedRecovery) {
            Activity::log('two_factor.recovery_code_used', $user, "{$user->email} signed in with a recovery code");
        }

        $response = $this->finishLogin($request, $user, $remember);
        if ($request->boolean('remember_device')) {
            $response->headers->setCookie($twoFactor->deviceCookie($user));
        }

        return $response;
    }

    private function finishLogin(Request $request, User $user, bool $remember, bool $home = false): RedirectResponse
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $home ? redirect($this->home($user)) : redirect()->intended($this->home($user));
    }

    private function pendingTwoFactorUser(Request $request): ?User
    {
        $pending = $request->session()->get('two_factor');
        if (! is_array($pending) || ($pending['expires'] ?? 0) < now()->getTimestamp()) {
            $request->session()->forget('two_factor');

            return null;
        }
        $user = User::find($pending['id'] ?? 0);

        return $user && $user->is_active && $user->hasTwoFactor() ? $user : null;
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function showRegister()
    {
        abort_unless($this->registrationOpen(), 404);

        return view('auth.register');
    }

    public function register(Request $request, Registration $registration): RedirectResponse
    {
        abort_unless($this->registrationOpen(), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
            'country' => ['nullable', 'string', 'size:2'],
        ]);
        $result = $registration->createClient([
            'name' => $data['name'],
            'company_name' => $data['company_name'] ?? null,
            'email' => $data['email'],
            'country' => $data['country'] ?? null,
        ], $data['password']);

        Auth::login($result['user']);
        $request->session()->regenerate();

        return redirect()->intended(route('portal.dashboard'));
    }

    public function showForgot()
    {
        return view('auth.forgot');
    }

    public function sendReset(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));

        // Same answer whether or not the account exists, so the form cannot be used to find customers.
        return back()->with('status', 'If that email has an account, a reset link is on its way.');
    }

    public function showReset(Request $request, string $token)
    {
        return view('auth.reset', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate(['token' => ['required'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', PasswordRule::min(8)]]);
        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect()->route('login')->with('status', 'Your password has been reset. Sign in with the new one.');
    }

    public function home(User $user): string
    {
        return $user->isStaff() ? route('admin.dashboard') : route('portal.dashboard');
    }

    private function registrationOpen(): bool
    {
        return config('billing.registration') && Settings::bool('general.registration', true);
    }
}
