<?php

namespace App\Support;

class Countries
{
    /** @var array<string, string>|null */
    private static ?array $all = null;

    /** @return array<string, string> ISO 3166-1 alpha-2 => English name */
    public static function all(): array
    {
        return self::$all ??= require __DIR__.'/country-names.php';
    }

    public static function name(?string $code): string
    {
        return $code ? (self::all()[strtoupper($code)] ?? strtoupper($code)) : '';
    }
}
