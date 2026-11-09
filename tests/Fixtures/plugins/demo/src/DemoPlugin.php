<?php

namespace Fixtures\Demo;

use App\Events\InvoicePaid;
use App\Plugins\Hooks;
use App\Plugins\Plugin;

class DemoPlugin extends Plugin
{
    public static array $seen = [];

    public function fields(): array
    {
        return [
            ['key' => 'greeting', 'label' => 'Greeting', 'default' => 'Hello'],
            ['key' => 'api_key', 'label' => 'API key', 'type' => 'password'],
        ];
    }

    public function register(): void
    {
        $this->registerModule(DemoModule::class);
    }

    public function boot(): void
    {
        $this->loadViews();
        $this->loadMigrations();
        $this->loadRoutes('routes/admin.php', 'admin');
        $this->loadRoutes('routes/public.php', 'public');
        $this->adminMenu('Demo page', 'admin.demo.index', 'puzzle', 'Demo');
        $this->extend('admin.dashboard', ['view' => 'demo::widget']);
        $this->listen(InvoicePaid::class, fn (InvoicePaid $e) => self::$seen[] = $e->model->number);
        Hooks::filter('demo.value', fn ($v) => $v.'+plugin');
    }
}
