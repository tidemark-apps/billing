<?php

namespace App\Providers;

use App\Plugins\Extensions;
use App\Plugins\GatewayRegistry;
use App\Plugins\ModuleRegistry;
use App\Plugins\PluginManager;
use Illuminate\Support\ServiceProvider;

class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PluginManager::class);
        $this->app->singleton(Extensions::class);
        $this->app->singleton(GatewayRegistry::class);
        $this->app->singleton(ModuleRegistry::class);

        $this->app->make(PluginManager::class)->loadEnabled();
    }

    public function boot(): void
    {
        $this->app->make(PluginManager::class)->bootEnabled();
    }
}
