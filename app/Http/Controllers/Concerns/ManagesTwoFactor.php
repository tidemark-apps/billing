<?php

namespace App\Http\Controllers\Concerns;

use App\Services\TwoFactor;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** The account security page actions, shared by the staff and client-area controllers. Needs $routePrefix. */
trait ManagesTwoFactor
{
    /** @return array<string, mixed> view data for the security panel */
    protected function panel(Request $request): array
    {
        $user = $request->user();
        $svc = app(TwoFactor::class);
        $data = [
            'user' => $user,
            'enabled' => $user->hasTwoFactor(),
            'pending' => $svc->pendingEnrollment($user),
            'recoveryCodes' => session('two_factor_codes'),
            'remaining' => $user->hasTwoFactor() ? $svc->remainingRecoveryCodes($user) : 0,
            'prefix' => $this->routePrefix,
            'required' => $user->isStaff() && Settings::bool('security.require_staff_2fa'),
            'devices' => collect(),
            'currentDeviceId' => null,
        ];
        if ($data['enabled']) {
            $data['devices'] = $svc->devices($user);
            $data['currentDeviceId'] = $svc->currentDevice($user, $request->cookie(TwoFactor::DEVICE_COOKIE))?->id;
        }
        if ($data['pending']) {
            $data['secret'] = $user->two_factor_secret;
            $data['uri'] = $svc->uri($user);
            $data['qr'] = $svc->qrSvg($data['uri']);
        }

        return $data;
    }

    public function start(Request $request): RedirectResponse
    {
        $this->denyWhileImpersonating($request);
        abort_if($request->user()->hasTwoFactor(), 403, 'Two-factor authentication is already on.');
        app(TwoFactor::class)->beginEnrollment($request->user());

        return $this->back();
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->denyWhileImpersonating($request);
        if (app(TwoFactor::class)->pendingEnrollment($request->user())) {
            app(TwoFactor::class)->reset($request->user());
        }

        return $this->back();
    }

    public function confirm(Request $request): RedirectResponse
    {
        $this->denyWhileImpersonating($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $this->throttle($request, 'code');
        $codes = app(TwoFactor::class)->confirmEnrollment($request->user(), preg_replace('/\s+/', '', $data['code']) ?? '');
        if ($codes === null) {
            RateLimiter::hit($this->key($request), 300);
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check the time on your phone and try again.']);
        }
        RateLimiter::clear($this->key($request));
        Activity::log('two_factor.enabled', $request->user(), "{$request->user()->email} turned on two-factor authentication");

        return $this->back()->with('two_factor_codes', $codes)->with('status', 'Two-factor authentication is on.');
    }

    public function recoveryCodes(Request $request): RedirectResponse
    {
        $this->denyWhileImpersonating($request);
        abort_unless($request->user()->hasTwoFactor(), 403);
        $this->requirePassword($request);
        $codes = app(TwoFactor::class)->regenerateRecoveryCodes($request->user());
        Activity::log('two_factor.recovery_codes_regenerated', $request->user(), "{$request->user()->email} regenerated recovery codes");

        return $this->back()->with('two_factor_codes', $codes)->with('status', 'New recovery codes generated. The old ones no longer work.');
    }

    public function disable(Request $request): RedirectResponse
    {
        $this->denyWhileImpersonating($request);
        $user = $request->user();
        abort_unless($user->hasTwoFactor(), 403);
        $request->validate(['code' => ['required', 'string', 'max:64']]);
        $this->requirePassword($request);
        $this->throttle($request, 'code');
        if (! app(TwoFactor::class)->verify($user, $request->input('code'))) {
            RateLimiter::hit($this->key($request), 300);
            throw ValidationException::withMessages(['code' => 'That code is not valid.']);
        }
        RateLimiter::clear($this->key($request));
        app(TwoFactor::class)->reset($user);
        Activity::log('two_factor.disabled', $user, "{$user->email} turned off two-factor authentication");

        return $this->back()->with('status', 'Two-factor authentication is off.')->withCookie(Cookie::forget(TwoFactor::DEVICE_COOKIE));
    }

    /** Stop trusting one remembered device: it will be asked for a code again at the next sign-in. */
    public function revokeDevice(Request $request, int $device): RedirectResponse
    {
        $this->denyWhileImpersonating($request);
        $svc = app(TwoFactor::class);
        $current = $svc->currentDevice($request->user(), $request->cookie(TwoFactor::DEVICE_COOKIE));
        abort_unless($svc->revokeDevice($request->user(), $device), 404);
        Activity::log('two_factor.device_revoked', $request->user(), "{$request->user()->email} revoked a trusted device", ['device_id' => $device]);
        $response = $this->back()->with('status', 'Device revoked. It will ask for a code at the next sign-in.');

        return $current?->id === $device ? $response->withCookie(Cookie::forget(TwoFactor::DEVICE_COOKIE)) : $response;
    }

    public function revokeAllDevices(Request $request): RedirectResponse
    {
        $this->denyWhileImpersonating($request);
        $count = app(TwoFactor::class)->revokeAllDevices($request->user());
        Activity::log('two_factor.devices_revoked', $request->user(), "{$request->user()->email} revoked all trusted devices", ['count' => $count]);

        return $this->back()->with('status', 'All trusted devices revoked.')->withCookie(Cookie::forget(TwoFactor::DEVICE_COOKIE));
    }

    /** Staff "viewing as client" must not be able to enrol or change the customer's second factor. */
    private function denyWhileImpersonating(Request $request): void
    {
        abort_if($request->session()->has('impersonator_id'), 403, 'Security settings cannot be changed while viewing as a client.');
    }

    private function requirePassword(Request $request): void
    {
        $request->validate(['current_password' => ['required', 'string']]);
        if (! Hash::check($request->input('current_password'), $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'That password is not correct.']);
        }
    }

    private function key(Request $request): string
    {
        return 'two-factor-settings:'.$request->user()->id.'|'.$request->ip();
    }

    private function throttle(Request $request, string $field): void
    {
        if (RateLimiter::tooManyAttempts($this->key($request), 5)) {
            throw ValidationException::withMessages([$field => 'Too many attempts. Try again in '.RateLimiter::availableIn($this->key($request)).' seconds.']);
        }
    }

    private function back(): RedirectResponse
    {
        return redirect()->route($this->routePrefix.'.index');
    }
}
