<?php

namespace Plugins\LicenseServer;

use Plugins\LicenseServer\Models\License;

/** Keys look like PREFIX-XXXX-XXXX-XXXX-CCCC. The last group is a checksum, so typos are rejected before any database lookup. */
class KeyGenerator
{
    /** No I, O, 0 or 1. */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function cleanPrefix(?string $prefix): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $prefix));

        return substr($prefix, 0, 10) ?: 'LIC';
    }

    public static function generate(?string $prefix = null): string
    {
        $prefix = self::cleanPrefix($prefix);
        do {
            $body = self::random(12);
            $key = $prefix.'-'.implode('-', str_split($body, 4)).'-'.self::checksum($prefix, $body);
        } while (License::where('key', $key)->exists());

        return $key;
    }

    public static function isWellFormed(string $key): bool
    {
        if (! preg_match('/^([A-Z0-9]{1,10})-([A-Z2-9]{4})-([A-Z2-9]{4})-([A-Z2-9]{4})-([A-Z2-9]{4})$/', $key, $m)) {
            return false;
        }

        return hash_equals(self::checksum($m[1], $m[2].$m[3].$m[4]), $m[5]);
    }

    private static function random(int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, 31)];
        }

        return $out;
    }

    private static function checksum(string $prefix, string $body): string
    {
        $hash = hash('sha256', 'license-v1|'.$prefix.'|'.$body, true);
        $out = '';
        for ($i = 0; $i < 4; $i++) {
            $out .= self::ALPHABET[ord($hash[$i]) % 32];
        }

        return $out;
    }
}
