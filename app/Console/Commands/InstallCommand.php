<?php

namespace App\Console\Commands;

use App\Support\Installer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
    protected $signature = 'billing:install
        {--name= : Admin name (env BILLING_ADMIN_NAME)}
        {--email= : Admin email (env BILLING_ADMIN_EMAIL)}
        {--password= : Admin password (env BILLING_ADMIN_PASSWORD)}
        {--company= : Company name (env BILLING_COMPANY)}
        {--currency= : Default currency (env BILLING_CURRENCY)}
        {--plugin=* : Plugins to enable}';

    protected $description = 'Run migrations and create the first admin without the web installer (for Docker and scripts)';

    public function handle(): int
    {
        if (is_file(storage_path('app/installed'))) {
            $this->info('Already installed.');

            return self::SUCCESS;
        }
        $email = $this->option('email') ?: env('BILLING_ADMIN_EMAIL');
        $password = $this->option('password') ?: env('BILLING_ADMIN_PASSWORD');
        $generated = false;
        if ($email && ! $password && config('billing.hosted')) {
            $password = Str::random(40); // nobody knows it; the owner signs in with `billing:login-link` and sets their own
            $generated = true;
        }
        if (! $email || ! $password) {
            $this->error('Provide --email and --password (or BILLING_ADMIN_EMAIL / BILLING_ADMIN_PASSWORD), or open /install in a browser.');

            return self::FAILURE;
        }
        Installer::install([
            'name' => $this->option('name') ?: env('BILLING_ADMIN_NAME', 'Administrator'),
            'email' => $email,
            'password' => $password,
            'company' => $this->option('company') ?: env('BILLING_COMPANY', config('billing.name')),
            'currency' => $this->option('currency') ?: env('BILLING_CURRENCY', 'USD'),
            'plugins' => $this->option('plugin'),
        ]);
        $this->info($generated ? "Installed. Run `php artisan billing:login-link $email --reset` for the owner's first sign-in." : "Installed. Sign in as $email.");

        return self::SUCCESS;
    }
}
