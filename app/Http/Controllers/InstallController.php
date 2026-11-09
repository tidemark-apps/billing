<?php

namespace App\Http\Controllers;

use App\Plugins\PluginManager;
use App\Support\Installer;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class InstallController extends Controller
{
    public function show(PluginManager $plugins)
    {
        $checks = Installer::requirements();

        return view('install.index', [
            'checks' => $checks,
            'ready' => collect($checks)->every(fn ($c) => $c['ok']),
            'drivers' => array_filter(['sqlite' => extension_loaded('pdo_sqlite') ? 'SQLite (no setup)' : null, 'mysql' => extension_loaded('pdo_mysql') ? 'MySQL / MariaDB' : null, 'pgsql' => extension_loaded('pdo_pgsql') ? 'PostgreSQL' : null]),
            'plugins' => $plugins->discover(),
            'currencies' => Money::currencies(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'db_driver' => ['required', 'in:sqlite,mysql,pgsql'],
            'db_host' => ['nullable', 'string'],
            'db_port' => ['nullable', 'integer'],
            'db_database' => ['nullable', 'string'],
            'db_username' => ['nullable', 'string'],
            'db_password' => ['nullable', 'string'],
            'company' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'plugins' => ['nullable', 'array'],
        ]);

        try {
            Installer::configureDatabase([
                'driver' => $data['db_driver'], 'host' => $data['db_host'] ?? null, 'port' => $data['db_port'] ?? null,
                'database' => $data['db_driver'] === 'sqlite' ? null : ($data['db_database'] ?? null), 'username' => $data['db_username'] ?? null, 'password' => $data['db_password'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return back()->withInput($request->except('password', 'password_confirmation', 'db_password'))->withErrors(['db_driver' => 'Could not connect to the database: '.$e->getMessage()]);
        }

        $admin = Installer::install([
            'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'company' => $data['company'],
            'currency' => strtoupper($data['currency']), 'url' => $request->getSchemeAndHttpHost().rtrim($request->getBasePath(), '/'),
            'plugins' => array_keys(array_filter($data['plugins'] ?? [])),
        ]);
        Auth::login($admin);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard')->with('status', 'Installed. Welcome aboard! Add your first product to get started.');
    }
}
