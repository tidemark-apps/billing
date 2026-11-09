<?php

namespace Plugins\LicenseServer;

use App\Plugins\Hooks;
use App\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Plugins\LicenseServer\Events\LicenseRevoked;
use Plugins\LicenseServer\Models\Activation;
use Plugins\LicenseServer\Models\License;

/** Business rules for the public verify / activate / deactivate API. Returns [httpStatus, body]. */
class LicenseManager
{
    /** @return array{0: int, 1: array<string, mixed>} */
    public function verify(Request $request): array
    {
        [$license, $failure] = $this->resolve($request);
        if ($failure) {
            return $failure;
        }
        $identifier = $this->identifier($license, $request);
        if ($identifier === false) {
            return $this->fail('identifier_required', 'This licence needs an identifier (domain, IP or instance id).', 422);
        }
        if (! $license->isUsable()) {
            return $this->fail($license->effectiveStatus(), 'This licence is '.$license->effectiveStatus().'.', 403, $license);
        }
        $activation = null;
        if ($license->binding !== 'none') {
            $activation = $license->activations()->where('identifier', $identifier)->first();
            if (! $activation) {
                return $this->fail('not_activated', 'This licence is not activated for that identifier.', 403, $license);
            }
            if (Settings::bool('plugin.license-server.verify_marks_seen', true)) {
                $this->touch($activation, $request);
            }
        }

        return [200, $this->success($license, $identifier, $request)];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    public function activate(Request $request): array
    {
        [$license, $failure] = $this->resolve($request);
        if ($failure) {
            return $failure;
        }
        $identifier = $this->identifier($license, $request);
        if ($identifier === false) {
            return $this->fail('identifier_required', 'This licence needs an identifier (domain, IP or instance id).', 422);
        }
        if (! $license->isUsable()) {
            return $this->fail($license->effectiveStatus(), 'This licence is '.$license->effectiveStatus().'.', 403, $license);
        }
        if ($license->binding !== 'none') {
            $blocked = DB::transaction(function () use ($license, $identifier, $request) {
                $license = License::whereKey($license->id)->lockForUpdate()->first();
                $existing = $license->activations()->where('identifier', $identifier)->first();
                if ($existing) {
                    $this->touch($existing, $request);

                    return false;
                }
                if ($license->activations()->count() >= $license->max_activations) {
                    return true;
                }
                try {
                    $license->activations()->create([
                        'identifier' => $identifier, 'ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                        'version' => $request->input('version'), 'first_seen_at' => now(), 'last_seen_at' => now(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // a concurrent identical activation won the race: that is the idempotent outcome
                }

                return false;
            });
            if ($blocked) {
                return $this->fail('activation_limit', 'The maximum number of activations has been reached.', 409, $license);
            }
        }

        return [200, $this->success($license->refresh(), $identifier, $request)];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    public function deactivate(Request $request): array
    {
        [$license, $failure] = $this->resolve($request);
        if ($failure) {
            return $failure;
        }
        $identifier = $this->identifier($license, $request);
        if ($identifier === false) {
            return $this->fail('identifier_required', 'An identifier is required.', 422);
        }
        if ($identifier !== null) {
            $license->activations()->where('identifier', $identifier)->delete();
        }

        return [200, ['deactivated' => true, 'activations' => $this->counts($license->refresh())]];
    }

    public function revoke(License $license, string $reason = 'revoked'): void
    {
        if ($license->status === 'revoked') {
            return;
        }
        $license->update(['status' => 'revoked']);
        event(new LicenseRevoked($license, $reason));
    }

    /** The key and product must both match, otherwise the caller learns nothing beyond "invalid". @return array{0: ?License, 1: ?array{0: int, 1: array<string, mixed>}} */
    private function resolve(Request $request): array
    {
        $key = strtoupper(trim((string) $request->input('key')));
        $product = trim((string) $request->input('product'));
        $license = KeyGenerator::isWellFormed($key) ? License::where('key', $key)->first() : null;
        if (! $license || ! hash_equals($license->product_code, $product)) {
            return [null, $this->fail('invalid', 'Invalid licence.', 403)];
        }

        return [$license, null];
    }

    /** @return string|false|null a normalised identifier, null when the binding needs none, false when one is required but missing or malformed */
    private function identifier(License $license, Request $request): string|false|null
    {
        $raw = trim((string) $request->input('identifier'));

        return match ($license->binding) {
            'none' => null,
            'ip' => filter_var($raw !== '' ? $raw : (string) $request->ip(), FILTER_VALIDATE_IP) ? strtolower($raw !== '' ? $raw : (string) $request->ip()) : false,
            'domain' => ($d = self::normaliseDomain($raw)) !== '' ? $d : false,
            default => $raw === '' ? false : mb_substr($raw, 0, 191),
        };
    }

    public static function normaliseDomain(string $value): string
    {
        $value = strtolower(trim($value));
        $host = str_contains($value, '://') ? (parse_url($value, PHP_URL_HOST) ?: '') : $value;
        $host = preg_replace('#[/?\#].*$#', '', $host);
        $host = preg_replace('/:\d+$/', '', $host);
        $host = preg_replace('/^www\./', '', rtrim($host, '.'));

        return preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host) ? $host : '';
    }

    private function touch(Activation $a, Request $request): void
    {
        $a->update(['last_seen_at' => now(), 'ip' => $request->ip(), 'version' => $request->input('version') ?: $a->version]);
    }

    /** @return array{used: int, max: int} */
    private function counts(License $license): array
    {
        return ['used' => $license->activations()->count(), 'max' => $license->max_activations];
    }

    /** @return array<string, mixed> */
    private function success(License $license, ?string $identifier, Request $request): array
    {
        $issued = time();
        $expires = $license->expires_at?->toIso8601String();
        $status = $license->effectiveStatus();
        $body = [
            'valid' => true,
            'status' => $status,
            'expires_at' => $expires,
            'issued_at' => $issued,
            'activations' => $this->counts($license),
            'signature' => Signer::sign(Signer::payload($license->key, $license->product_code, $status, $expires, $identifier, $issued, true)),
        ];

        // Other plugins may add (unsigned) fields to the response.
        return Hooks::apply('license-server.response', $body, $license, $request);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function fail(string $code, string $message, int $status, ?License $license = null): array
    {
        $body = ['valid' => false, 'error' => ['code' => $code, 'message' => $message]];
        if ($license) {
            $body['status'] = $license->effectiveStatus();
        }

        return [$status, $body];
    }
}
