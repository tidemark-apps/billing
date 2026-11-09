<?php

namespace App\Support\TwoFactor;

/** RFC 6238 time-based one-time passwords (HMAC-SHA1, 30 second step, 6 digits), the profile every authenticator app uses. */
class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    public static function generateSecret(int $bytes = 20): string
    {
        return Base32::encode(random_bytes($bytes));
    }

    public static function timestep(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    /** The code for one time step (RFC 4226 dynamic truncation). */
    public static function code(string $base32Secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), Base32::decode($base32Secret), true);
        $offset = ord($hash[19]) & 0xF;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Returns the matching time step (within +/- $window steps), or null. Callers store the step to refuse replays.
     */
    public static function verify(string $base32Secret, string $code, ?int $time = null, int $window = 1): ?int
    {
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }
        $now = self::timestep($time);
        $matched = null;
        for ($i = -$window; $i <= $window; $i++) {
            // No early exit: constant work whatever the outcome.
            if (hash_equals(self::code($base32Secret, $now + $i), $code)) {
                $matched = $now + $i;
            }
        }

        return $matched;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?'.http_build_query([
            'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
