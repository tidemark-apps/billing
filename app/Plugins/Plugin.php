<?php

namespace App\Plugins;

use App\Contracts\PaymentGateway;
use App\Contracts\ProvisioningModule;
use App\Support\Settings;
use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

/**
 * Base class for a plugin's entry point (the "provider" class named in plugin.json).
 *
 * Lifecycle: register() runs early while the app is booting (register gateways, modules, bindings);
 * boot() runs once everything is registered (routes, views, menus, listeners).
 * Plugins are only loaded while enabled. install() runs once, the first time the plugin is enabled, after its migrations.
 */
abstract class Plugin
{
    /** @param  array<string, mixed>  $manifest */
    public function __construct(public readonly array $manifest, public readonly string $path) {}

    public function slug(): string
    {
        return $this->manifest['slug'];
    }

    public function register(): void {}

    public function boot(): void {}

    public function install(): void {}

    public function uninstall(): void {}

    /** @return array<int, array<string, mixed>> settings fields (see App\Support\Fields); stored under "plugin.{slug}." */
    public function fields(): array
    {
        return [];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return Settings::get('plugin.'.$this->slug().'.'.$key, $default);
    }

    /** @return array<int, string> absolute migration directories */
    public function migrationPaths(): array
    {
        $dir = $this->path.'/database/migrations';

        return is_dir($dir) ? [$dir] : [];
    }

    protected function registerGateway(PaymentGateway|string $gateway): void
    {
        app(GatewayRegistry::class)->register($gateway);
    }

    protected function registerModule(ProvisioningModule|string $module): void
    {
        app(ModuleRegistry::class)->register($module);
    }

    /** Views become available as "{slug}::name", from the plugin's resources/views folder. */
    protected function loadViews(string $dir = 'resources/views'): void
    {
        if (is_dir($this->path.'/'.$dir)) {
            view()->addNamespace($this->slug(), $this->path.'/'.$dir);
        }
    }

    protected function loadMigrations(): void
    {
        foreach ($this->migrationPaths() as $path) {
            app('migrator')->path($path);
        }
    }

    /**
     * Load a routes file under one of the standard areas:
     *   admin   /admin/x/{slug}      staff only      route names admin.{slug}.*
     *   portal  /portal/x/{slug}     customers       route names portal.{slug}.*
     *   api     /api/v1/x/{slug}     API (bearer)    route names api.{slug}.*  (add your own auth middleware)
     *   public  /x/{slug}            anyone          route names {slug}.*
     *   webhook /webhooks/{slug}     no CSRF/session route names webhook.{slug}.*
     */
    protected function loadRoutes(string $file, string $area): void
    {
        $slug = $this->slug();
        $path = str_starts_with($file, '/') ? $file : $this->path.'/'.$file;
        $group = match ($area) {
            'admin' => ['prefix' => "admin/x/$slug", 'as' => "admin.$slug.", 'middleware' => ['web', 'auth', 'staff']],
            'portal' => ['prefix' => "portal/x/$slug", 'as' => "portal.$slug.", 'middleware' => ['web', 'auth', 'customer']],
            'api' => ['prefix' => "api/v1/x/$slug", 'as' => "api.$slug.", 'middleware' => ['api']],
            'webhook' => ['prefix' => "webhooks/$slug", 'as' => "webhook.$slug.", 'middleware' => ['api']],
            default => ['prefix' => "x/$slug", 'as' => "$slug.", 'middleware' => ['web']],
        };
        Route::group($group, fn () => require $path);
        // names are set after a route is added; refresh so route() and Route::has() see them even when a plugin loads late
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }

    /** @param  array<string, mixed>  $item see Extensions for the slots */
    protected function extend(string $slot, array $item): void
    {
        app(Extensions::class)->add($slot, $item);
    }

    protected function adminMenu(string $label, string $route, string $icon = 'puzzle', string $group = 'Plugins'): void
    {
        $this->extend('admin.menu', compact('label', 'route', 'icon', 'group'));
    }

    protected function portalMenu(string $label, string $route, string $icon = 'puzzle', string $group = 'More'): void
    {
        $this->extend('portal.menu', compact('label', 'route', 'icon', 'group'));
    }

    /** @param  class-string|string  $event a DomainEvent class, any Laravel event, or an event name */
    protected function listen(string $event, Closure|string|array $listener): void
    {
        Event::listen($event, $listener);
    }

    /** @param  Closure(Schedule): void  $callback */
    protected function schedule(Closure $callback): void
    {
        app()->booted(fn () => $callback(app(Schedule::class)));
    }
}
