<?php

namespace App\Services;

use App\Models\TwoFactorDevice;
use App\Models\User;
use App\Support\Settings;
use App\Support\TwoFactor\Totp;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Two-factor authentication: TOTP enrolment, code/recovery-code verification and "remember this device".
 * Reusable outside the web login (the REST token endpoint can call verify()).
 */
class TwoFactor
{
    public const DEVICE_COOKIE = 'tidemark_2fa_device';

    public const DEVICE_DAYS = 30;

    public const RECOVERY_CODES = 10;

    /** Start (or restart) enrolment: a fresh secret that does nothing until a code is confirmed. */
    public function beginEnrollment(User $user): string
    {
        $secret = Totp::generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_step' => null,
        ])->save();

        return $secret;
    }

    public function pendingEnrollment(User $user): bool
    {
        return $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;
    }

    public function uri(User $user): string
    {
        $issuer = (string) (Settings::get('company.name') ?: config('billing.name'));

        return Totp::uri((string) $user->two_factor_secret, $user->email, $issuer);
    }

    /** Inline SVG markup for the otpauth URI (no GD/imagick needed). */
    public function qrSvg(string $uri): string
    {
        $svg = (new QRCode(new QROptions([
            'outputType' => QRMarkupSVG::class,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'drawLightModules' => false,
            'svgPreserveAspectRatio' => 'xMidYMid meet',
        ])))->render($uri);

        return (string) $svg;
    }

    /** Confirm enrolment with a first valid code. Returns the one-time recovery codes, or null when the code is wrong. */
    public function confirmEnrollment(User $user, string $code): ?array
    {
        if (! $this->pendingEnrollment($user) || ! $this->acceptTotp($user, $code)) {
            return null;
        }
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_device_salt' => Str::random(40)])->save();

        return $this->regenerateRecoveryCodes($user);
    }

    /** @return array<int, string> plain codes, shown to the user once */
    public function regenerateRecoveryCodes(User $user): array
    {
        $plain = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $plain[] = strtolower(Str::random(5).'-'.Str::random(5));
        }
        $plain = array_map(fn ($c) => str_replace(['l', 'o', '0', '1'], ['m', 'p', 'q', 'r'], $c), $plain);
        $user->forceFill(['two_factor_recovery_codes' => json_encode(array_map($this->hashCode(...), $plain))])->save();

        return $plain;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($this->storedCodes($user));
    }

    /** Verify a TOTP code or a recovery code. A TOTP step and a recovery code each work only once. */
    public function verify(User $user, string $code): bool
    {
        if (! $user->hasTwoFactor()) {
            return false;
        }
        $code = trim($code);
        $digits = preg_replace('/[\s-]/', '', $code) ?? '';
        if (preg_match('/^\d{6}$/', $digits)) {
            return $this->acceptTotp($user, $digits);
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    /** Remove 2FA entirely (disable, admin reset, console reset). Also removes every remembered device. */
    public function reset(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_step' => null,
            'two_factor_device_salt' => null,
        ])->save();
        $this->revokeAllDevices($user);
    }

    // ---- remember this device ---------------------------------------------------------------------------------
    // The browser holds an HttpOnly, encrypted+signed cookie carrying a random token. The server keeps only its hash in
    // two_factor_devices, so every device can be listed and revoked on its own. Cookies of the old scheme
    // ("id|expires|signature") do not match any row and simply stop working.

    /** Remember the current browser for DEVICE_DAYS: stores a device row and returns the cookie to send. */
    public function deviceCookie(User $user, ?Request $request = null): Cookie
    {
        $request ??= request();
        $this->pruneDevices();
        $token = Str::random(48);
        $expires = now()->addDays(self::DEVICE_DAYS);
        $agent = (string) $request->userAgent();
        $user->twoFactorDevices()->create([
            'token_hash' => $this->deviceHash($token),
            'binding' => $this->deviceBinding($user),
            'label' => $this->describeAgent($agent),
            'user_agent' => mb_substr($agent, 0, 255) ?: null,
            'ip' => $request->ip(),
            'last_used_at' => now(),
            'expires_at' => $expires,
        ]);

        return new Cookie(self::DEVICE_COOKIE, $token, $expires->getTimestamp(), '/', null, $request->isSecure(), true, false, 'lax');
    }

    /** The device row this cookie belongs to, if it is still valid (not expired, not revoked, password unchanged). */
    public function currentDevice(User $user, ?string $cookie): ?TwoFactorDevice
    {
        if (! $user->hasTwoFactor() || ! is_string($cookie) || ! preg_match('/^[A-Za-z0-9]{48}$/', $cookie)) {
            return null;
        }
        $device = $user->twoFactorDevices()->where('token_hash', $this->deviceHash($cookie))->first();
        if (! $device) {
            return null;
        }
        if ($device->expires_at->isPast() || ! hash_equals($device->binding, $this->deviceBinding($user))) {
            $device->delete();

            return null;
        }

        return $device;
    }

    /** True when the cookie is a valid remembered device (and marks it as used). */
    public function deviceTrusted(User $user, ?string $cookie): bool
    {
        $device = $this->currentDevice($user, $cookie);
        if (! $device) {
            return false;
        }
        if (! $device->last_used_at || $device->last_used_at->lt(now()->subMinutes(5))) {
            $device->forceFill(['last_used_at' => now(), 'ip' => request()->ip() ?: $device->ip])->saveQuietly();
        }

        return true;
    }

    /** @return Collection<int, TwoFactorDevice> newest first, expired ones excluded */
    public function devices(User $user)
    {
        $this->pruneDevices();

        return $user->twoFactorDevices()->where('expires_at', '>', now())->latest('last_used_at')->latest('id')->get();
    }

    public function revokeDevice(User $user, int $id): bool
    {
        return $user->twoFactorDevices()->whereKey($id)->delete() > 0;
    }

    /** @return int number of devices removed */
    public function revokeAllDevices(User $user): int
    {
        return $user->twoFactorDevices()->delete();
    }

    /** Housekeeping: expired rows are removed whenever devices are listed or added (and by `php artisan model:prune`). */
    public function pruneDevices(): void
    {
        TwoFactorDevice::where('expires_at', '<', now())->delete();
    }

    /** Bound to the user's password hash: changing the password makes every remembered device untrusted. */
    private function deviceBinding(User $user): string
    {
        return hash_hmac('sha256', $user->id.'|'.$user->password, (string) config('app.key'));
    }

    private function deviceHash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** "Firefox on Windows": a short, human description of a User-Agent header. */
    public function describeAgent(string $agent): string
    {
        $browser = match (true) {
            $agent === '' => null,
            (bool) preg_match('~Edg(e|A|iOS)?/~', $agent) => 'Edge',
            (bool) preg_match('~OPR/|Opera~', $agent) => 'Opera',
            (bool) preg_match('~Firefox/|FxiOS/~', $agent) => 'Firefox',
            (bool) preg_match('~Chrome/|CriOS/~', $agent) => 'Chrome',
            (bool) preg_match('~Safari/~', $agent) => 'Safari',
            (bool) preg_match('~curl/~i', $agent) => 'curl',
            default => null,
        };
        $os = match (true) {
            (bool) preg_match('~iPhone|iPad|iPod~', $agent) => 'iOS',
            (bool) preg_match('~Android~', $agent) => 'Android',
            (bool) preg_match('~Windows~', $agent) => 'Windows',
            (bool) preg_match('~Mac OS X|Macintosh~', $agent) => 'macOS',
            (bool) preg_match('~CrOS~', $agent) => 'ChromeOS',
            (bool) preg_match('~Linux|X11~', $agent) => 'Linux',
            default => null,
        };

        return match (true) {
            $browser && $os => "$browser on $os",
            (bool) $browser => $browser,
            (bool) $os => $os,
            default => 'Unknown device',
        };
    }

    // ---- staff enforcement --------------------------------------------------------------------------------------

    public function staffMustEnrol(User $user): bool
    {
        return $user->isStaff() && ! $user->hasTwoFactor() && Settings::bool('security.require_staff_2fa');
    }

    // ---- internals ------------------------------------------------------------------------------------------------

    private function acceptTotp(User $user, string $code): bool
    {
        $step = Totp::verify((string) $user->two_factor_secret, $code);
        if ($step === null) {
            return false;
        }
        // Atomic: only the first request to present a given step wins, so a captured code cannot be replayed.
        $won = User::whereKey($user->id)
            ->where(fn ($q) => $q->whereNull('two_factor_last_step')->orWhere('two_factor_last_step', '<', $step))
            ->update(['two_factor_last_step' => $step]);
        if ($won) {
            $user->two_factor_last_step = $step;
            $user->syncOriginalAttribute('two_factor_last_step');
        }

        return (bool) $won;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $needle = $this->hashCode(strtolower($code));
        $raw = $user->getRawOriginal('two_factor_recovery_codes');
        $codes = $this->storedCodes($user);
        foreach ($codes as $i => $hash) {
            if (hash_equals($hash, $needle)) {
                unset($codes[$i]);
                // Compare-and-swap so the same code cannot be spent twice by parallel requests.
                $won = User::whereKey($user->id)->where('two_factor_recovery_codes', $raw)->update(['two_factor_recovery_codes' => json_encode(array_values($codes))]);
                if ($won) {
                    $user->refresh();
                }

                return (bool) $won;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function storedCodes(User $user): array
    {
        $codes = json_decode((string) $user->getRawOriginal('two_factor_recovery_codes'), true);

        return is_array($codes) ? $codes : [];
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', trim($code), (string) config('app.key'));
    }
}
