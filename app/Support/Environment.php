<?php

namespace App\Support;

class Environment
{
    /** Create .env from .env.example with a fresh APP_KEY when it is missing (first run on shared hosting). */
    public static function ensureFile(string $base): void
    {
        $env = $base.'/.env';
        if (is_file($env) || ! is_file($base.'/.env.example') || getenv('APP_KEY') || isset($_SERVER['APP_KEY'])) {
            return;
        }
        try {
            $contents = (string) file_get_contents($base.'/.env.example');
            $contents = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=base64:'.base64_encode(random_bytes(32)), $contents);
            @file_put_contents($env, $contents);
        } catch (\Throwable) {
        }
    }

    /** Update (or append) keys in the .env file. @param  array<string, string>  $values */
    public static function write(string $path, array $values): void
    {
        $contents = is_file($path) ? (string) file_get_contents($path) : '';
        foreach ($values as $key => $value) {
            $line = $key.'='.self::escape($value);
            if (preg_match('/^#?\s*'.preg_quote($key, '/').'=.*$/m', $contents)) {
                $contents = preg_replace('/^#?\s*'.preg_quote($key, '/').'=.*$/m', str_replace('\\', '\\\\', $line), $contents, 1);
            } else {
                $contents = rtrim($contents)."\n".$line."\n";
            }
        }
        file_put_contents($path, $contents);
    }

    private static function escape(string $value): string
    {
        return preg_match('/[\s#"\'\\\\$]/', $value) || $value === '' ? '"'.addcslashes($value, '"\\$').'"' : $value;
    }
}
