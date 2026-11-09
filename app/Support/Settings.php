<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

/**
 * Key/value settings stored in the database (company details, gateway keys, plugin options).
 * Values flagged secret are encrypted at rest with the application key.
 */
class Settings
{
    private const CACHE_KEY = 'billing.settings';

    /** @var array<string, array{value: ?string, secret: bool}>|null */
    private static ?array $memo = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = self::load()[$key] ?? null;
        if ($row === null || $row['value'] === null) {
            return $default;
        }
        if ($row['secret']) {
            try {
                return Crypt::decryptString($row['value']);
            } catch (\Throwable) {
                return $default;
            }
        }
        $decoded = json_decode($row['value'], true);

        return json_last_error() === JSON_ERROR_NONE && $decoded !== null ? $decoded : $row['value'];
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return filter_var(self::get($key, $default), FILTER_VALIDATE_BOOL);
    }

    public static function set(string $key, mixed $value, bool $secret = false): void
    {
        if ($value === null) {
            self::forget($key);

            return;
        }
        $stored = $secret ? Crypt::encryptString((string) $value) : (is_scalar($value) ? (string) $value : json_encode($value));
        if (is_bool($value)) {
            $stored = $value ? 'true' : 'false';
        }
        Setting::updateOrCreate(['key' => $key], ['value' => $stored, 'is_secret' => $secret]);
        self::flush();
    }

    /** @param  array<string, mixed>  $values */
    public static function setMany(array $values, bool $secret = false): void
    {
        foreach ($values as $k => $v) {
            self::set($k, $v, $secret);
        }
    }

    public static function forget(string $key): void
    {
        Setting::where('key', $key)->delete();
        self::flush();
    }

    /** All non-secret settings starting with a prefix, with the prefix stripped. @return array<string, mixed> */
    public static function group(string $prefix): array
    {
        $out = [];
        foreach (array_keys(self::load()) as $key) {
            if (str_starts_with($key, $prefix)) {
                $out[substr($key, strlen($prefix))] = self::get($key);
            }
        }

        return $out;
    }

    public static function flush(): void
    {
        self::$memo = null;
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
        }
    }

    /** @return array<string, array{value: ?string, secret: bool}> */
    private static function load(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }
        try {
            return self::$memo = Cache::remember(self::CACHE_KEY, 600, function () {
                if (! Schema::hasTable('settings')) {
                    return [];
                }

                return Setting::all()->mapWithKeys(fn (Setting $s) => [$s->key => ['value' => $s->value, 'secret' => (bool) $s->is_secret]])->all();
            });
        } catch (\Throwable) {
            return self::$memo = [];
        }
    }
}
