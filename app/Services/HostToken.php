<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * A short-lived sign-in token minted by the host: base64url(claims JSON) "." base64url(Ed25519 signature over the first part's
 * text), both without padding. Checked against BILLING_SSO_PUBLIC_KEY, for this host only, and usable once.
 */
class HostToken
{
    /** @return array<string, mixed>|null the claims, or null when the token is invalid, expired, for another host, or already used */
    public static function verify(string $token, string $host): ?array
    {
        $publicKey = base64_decode((string) config('billing.sso_public_key'), true);
        if (! $publicKey || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || substr_count($token, '.') !== 1) {
            return null;
        }
        [$part, $sig] = explode('.', $token);
        $signature = self::b64url($sig);
        if ($signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || ! sodium_crypto_sign_verify_detached($signature, $part, $publicKey)) {
            return null;
        }
        $claims = json_decode((string) self::b64url($part), true);
        if (! is_array($claims) || ! isset($claims['exp'], $claims['aud'], $claims['jti'], $claims['kind'])) {
            return null;
        }
        if ((int) $claims['exp'] < time() || (int) ($claims['iat'] ?? 0) > time() + 60 || strcasecmp((string) $claims['aud'], $host) !== 0) {
            return null;
        }
        if (! in_array($claims['kind'], ['customer', 'support'], true) || ! Cache::add('host-token:'.$claims['jti'], 1, now()->addMinutes(10))) {
            return null;
        }

        return $claims;
    }

    private static function b64url(string $s): ?string
    {
        $out = base64_decode(strtr($s, '-_', '+/'), true);

        return $out === false ? null : $out;
    }
}
