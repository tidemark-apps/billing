<?php

namespace App\Plugins;

use App\Models\PluginRecord;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finds plugin folders, tracks which are enabled, and loads them into the running app.
 *
 * A plugin is a folder under plugins/ (or BILLING_PLUGINS_PATH) containing a plugin.json:
 *   { "slug": "stripe", "name": "Stripe", "version": "1.0.0", "description": "…", "author": "…",
 *     "namespace": "Plugins\\Stripe\\", "provider": "Plugins\\Stripe\\StripePlugin", "requires": { "billing": ">=0.1" } }
 * Classes under the namespace are autoloaded from the plugin's src/ folder, no Composer needed.
 */
class PluginManager
{
    /** Written into a plugin folder by PluginInstaller; plugins without it ship with the release ("bundled") or were copied in by hand. */
    public const UPLOAD_MARKER = '.tidemark-installed';

    /** @var array<string, array<string, mixed>>|null slug => manifest (+ path, origin) */
    private ?array $discovered = null;

    /** @var array<string, Plugin> */
    private array $loaded = [];

    /** @var array<string, bool> */
    private array $autoloaded = [];

    /** @var array<string, bool> */
    private array $registered = [];

    /** @var array<string, bool> */
    private array $booted = [];

    /** @return array<string, array<string, mixed>> */
    public function discover(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }
        $this->discovered = [];
        $roots = array_filter([config('billing.plugins_path'), config('billing.plugins_extra_path')]);
        $files = array_merge(...array_map(fn ($root) => glob($root.'/*/plugin.json') ?: [], $roots ?: ['']));
        foreach ($files as $file) {
            $manifest = json_decode((string) file_get_contents($file), true);
            if (! is_array($manifest) || empty($manifest['slug']) || empty($manifest['provider']) || empty($manifest['namespace'])) {
                continue;
            }
            if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $manifest['slug'])) {
                continue;
            }
            $manifest['path'] = dirname($file);
            $manifest['origin'] = is_file($manifest['path'].'/'.self::UPLOAD_MARKER) ? 'uploaded' : 'bundled';
            $this->discovered[$manifest['slug']] = $manifest;
        }
        ksort($this->discovered);

        return $this->discovered;
    }

    /** Forget the cached folder scan (after installing or removing files). */
    public function refresh(): void
    {
        $this->discovered = null;
    }

    public function manifest(string $slug): ?array
    {
        return $this->discover()[$slug] ?? null;
    }

    /** @return array<string, bool> slug => enabled */
    public function states(): array
    {
        try {
            if (! Schema::hasTable('plugins')) {
                return [];
            }

            // Query builder, not Eloquent: this runs during provider register(), before models have a connection resolver.
            return DB::table('plugins')->pluck('enabled', 'slug')->map(fn ($v) => (bool) $v)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function isEnabled(string $slug): bool
    {
        return $this->states()[$slug] ?? false;
    }

    /** Instantiate every enabled plugin and call register(). Called by the service provider while booting. */
    public function loadEnabled(): void
    {
        foreach ($this->states() as $slug => $enabled) {
            if ($enabled && $this->manifest($slug) && $plugin = $this->load($slug)) {
                $this->registered[$slug] = true;
                $plugin->register();
            }
        }
    }

    public function bootEnabled(): void
    {
        foreach ($this->loaded as $slug => $plugin) {
            if (! empty($this->registered[$slug]) && empty($this->booted[$slug])) {
                $this->booted[$slug] = true;
                $plugin->boot();
            }
        }
    }

    /** Register and boot a plugin right now (used by tests and right after enabling), unless it already is. */
    public function bootNow(string $slug): void
    {
        $plugin = $this->load($slug);
        if (! $plugin) {
            return;
        }
        if (empty($this->registered[$slug])) {
            $this->registered[$slug] = true;
            $plugin->register();
        }
        if (empty($this->booted[$slug])) {
            $this->booted[$slug] = true;
            $plugin->boot();
        }
    }

    public function load(string $slug): ?Plugin
    {
        if (isset($this->loaded[$slug])) {
            return $this->loaded[$slug];
        }
        $manifest = $this->manifest($slug);
        if (! $manifest) {
            return null;
        }
        $this->autoload($manifest);
        $class = $manifest['provider'];
        if (! class_exists($class)) {
            report(new \RuntimeException("Plugin [$slug] provider class $class was not found."));

            return null;
        }

        return $this->loaded[$slug] = new $class($manifest, $manifest['path']);
    }

    /** @return array<string, Plugin> the enabled plugins that loaded */
    public function loaded(): array
    {
        return $this->loaded;
    }

    /** An instance for settings/migrations even when the plugin is disabled. */
    public function instance(string $slug): ?Plugin
    {
        return $this->load($slug);
    }

    public function enable(string $slug): void
    {
        $plugin = $this->instance($slug) ?? throw new \RuntimeException("Plugin [$slug] is not installed.");
        $manifest = $this->manifest($slug);
        $first = ! PluginRecord::where('slug', $slug)->exists();

        $runner = app(PluginMigrationRunner::class);
        $paths = $plugin->migrationPaths();
        $ran = $runner->run($paths); // throws PluginMigrationFailed after rolling back what it ran
        $original = PluginRecord::where('slug', $slug)->first()?->only(['enabled', 'version', 'installed_at']);

        try {
            PluginRecord::updateOrCreate(['slug' => $slug], [
                'enabled' => true,
                'version' => $manifest['version'] ?? null,
                'installed_at' => now(),
            ]);

            if ($first) {
                $plugin->install();
            }
        } catch (\Throwable $e) {
            $failure = $runner->abort($ran, $paths, $e, 'Enabling the plugin failed');
            $original === null ? PluginRecord::where('slug', $slug)->delete() : PluginRecord::where('slug', $slug)->update($original);
            throw $failure;
        }
        $this->clearCaches();
    }

    public function disable(string $slug): void
    {
        PluginRecord::where('slug', $slug)->update(['enabled' => false]);
        $this->clearCaches();
    }

    private function clearCaches(): void
    {
        try {
            Artisan::call('route:clear');
            Artisan::call('view:clear');
        } catch (\Throwable) {
        }
    }

    /** @param  array<string, mixed>  $manifest */
    private function autoload(array $manifest): void
    {
        $slug = $manifest['slug'];
        if (isset($this->autoloaded[$slug])) {
            return;
        }
        $this->autoloaded[$slug] = true;

        if (is_file($manifest['path'].'/vendor/autoload.php')) {
            require_once $manifest['path'].'/vendor/autoload.php';
        }
        $prefix = rtrim($manifest['namespace'], '\\').'\\';
        $base = $manifest['path'].'/src/';
        spl_autoload_register(function (string $class) use ($prefix, $base): void {
            if (str_starts_with($class, $prefix)) {
                $file = $base.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        });
    }
}
