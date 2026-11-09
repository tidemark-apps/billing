<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use App\Plugins\PluginManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class Installer
{
    public static function isInstalled(): bool
    {
        return app()->runningUnitTests() || is_file(storage_path('app/installed'));
    }

    /** @return array<int, array{label: string, ok: bool, hint: string}> */
    /** @return list<array{label: string, ok: bool, hint: string}> */
    public static function requirements(): array
    {
        $checks = [['PHP '.PHP_VERSION.' (8.3 or newer)', version_compare(PHP_VERSION, '8.3.0', '>='), 'Upgrade PHP in your hosting control panel.']];
        foreach (['mbstring', 'openssl', 'pdo', 'fileinfo', 'ctype', 'tokenizer', 'xml', 'curl', 'json', 'bcmath'] as $ext) {
            $checks[] = ["PHP extension: $ext", extension_loaded($ext), "Enable the $ext extension."];
        }
        $checks[] = ['A database driver (SQLite, MySQL or PostgreSQL)', extension_loaded('pdo_sqlite') || extension_loaded('pdo_mysql') || extension_loaded('pdo_pgsql'), 'Enable pdo_sqlite, pdo_mysql or pdo_pgsql.'];
        foreach (['storage', 'bootstrap/cache'] as $dir) {
            $checks[] = ["Writable: $dir/", is_writable(base_path($dir)), "Make $dir/ writable by the web server (chmod 775)."];
        }
        $checks[] = ['Writable: .env', is_writable(base_path('.env')) || (! is_file(base_path('.env')) && is_writable(base_path())), 'Make .env writable, or configure it by hand.'];

        return array_map(fn (array $c) => ['label' => $c[0], 'ok' => (bool) $c[1], 'hint' => $c[2]], $checks);
    }

    /** Point the app at a database for this request and persist it in .env. @param  array<string, mixed>  $db */
    public static function configureDatabase(array $db, bool $persist = true): void
    {
        $driver = $db['driver'] ?? 'sqlite';
        $env = ['DB_CONNECTION' => $driver];
        if ($driver === 'sqlite') {
            $path = $db['database'] ?? database_path('database.sqlite');
            if (! is_file($path)) {
                @mkdir(dirname($path), 0775, true);
                touch($path);
            }
            Config::set('database.connections.sqlite.database', $path);
            $env['DB_DATABASE'] = $path;
        } else {
            foreach (['host' => '127.0.0.1', 'port' => $driver === 'pgsql' ? '5432' : '3306', 'database' => '', 'username' => '', 'password' => ''] as $k => $d) {
                Config::set("database.connections.$driver.$k", $db[$k] ?? $d);
                $env['DB_'.strtoupper($k)] = (string) ($db[$k] ?? $d);
            }
            $env['DB_HOST'] = (string) ($db['host'] ?? '127.0.0.1');
        }
        Config::set('database.default', $driver);
        DB::purge();
        DB::connection()->getPdo();

        if ($persist) {
            Environment::write(base_path('.env'), $env);
        }
    }

    /**
     * Run migrations, create the first admin, store the basics and lock the installer.
     *
     * @param  array{name: string, email: string, password: string, company?: string, currency?: string, url?: string, plugins?: array<int, string>}  $data
     */
    public static function install(array $data): User
    {
        Artisan::call('migrate', ['--force' => true]);

        $admin = User::updateOrCreate(['email' => $data['email']], [
            'name' => $data['name'],
            'password' => $data['password'],
            'role' => Role::Admin,
            'is_active' => true,
        ]);
        Settings::setMany(array_filter([
            'company.name' => $data['company'] ?? config('billing.name'),
            'company.email' => $data['email'],
            'billing.currency' => $data['currency'] ?? 'USD',
        ]));
        Settings::set('general.registration', true);

        foreach ($data['plugins'] ?? [] as $slug) {
            app(PluginManager::class)->enable($slug);
        }
        if (! empty($data['url'])) {
            Environment::write(base_path('.env'), ['APP_URL' => rtrim($data['url'], '/')]);
        }
        @file_put_contents(storage_path('app/installed'), now()->toIso8601String());

        return $admin;
    }
}
