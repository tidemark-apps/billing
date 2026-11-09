<?php

namespace App\Console\Commands;

use App\Plugins\PluginConfirmationRequired;
use App\Plugins\PluginInstaller;
use App\Plugins\PluginInstallException;
use App\Plugins\PluginManager;
use App\Support\Activity;
use Illuminate\Console\Command;

class PluginCommand extends Command
{
    protected $signature = 'plugin {action : list|enable|disable|install|remove} {slug? : plugin slug, or a .zip path / https URL for install} {--force : skip confirmation prompts} {--drop-settings : with remove, also delete the plugin\'s saved settings}';

    protected $description = 'List, enable, disable, install (zip path or https URL) or remove plugins';

    public function handle(PluginManager $plugins, PluginInstaller $installer): int
    {
        $action = $this->argument('action');
        if ($action === 'list') {
            $states = $plugins->states();
            $this->table(['Slug', 'Name', 'Version', 'Enabled', 'Source'], collect($plugins->discover())->map(fn ($m, $slug) => [$slug, $m['name'] ?? $slug, $m['version'] ?? '-', ($states[$slug] ?? false) ? 'yes' : 'no', $m['origin'] ?? 'bundled'])->values()->all());

            return self::SUCCESS;
        }
        $slug = $this->argument('slug');
        if ($action === 'install') {
            return $this->install($installer, (string) $slug);
        }
        if (! $slug || ! $plugins->manifest($slug)) {
            $this->error('Unknown plugin. Run "plugin list".');

            return self::FAILURE;
        }
        if ($action === 'remove') {
            return $this->remove($installer, $plugins, $slug);
        }
        try {
            match ($action) {
                'enable' => $plugins->enable($slug),
                'disable' => $plugins->disable($slug),
                default => $this->error('Use list, enable, disable, install or remove.'),
            };
        } catch (\Throwable $e) {
            $this->error("Could not $action plugin $slug: ".$e->getMessage());

            return self::FAILURE;
        }
        $this->info("Plugin $slug: $action done.");

        return self::SUCCESS;
    }

    private function install(PluginInstaller $installer, string $source): int
    {
        if ($source === '') {
            $this->error('Give a path to a .zip file or an https:// URL.');

            return self::FAILURE;
        }
        $this->warn('Plugins run arbitrary PHP with full access to your data. Only install plugins you trust.');
        $temp = null;
        try {
            if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $source)) {
                $source = $temp = $installer->download($source);
            } elseif (! is_file($source)) {
                $this->error("File not found: $source");

                return self::FAILURE;
            }
            try {
                $result = $installer->install($source);
            } catch (PluginConfirmationRequired $e) {
                $this->line("{$e->name} is already installed: {$e->from} → {$e->to} ({$e->relation}).");
                if (! $this->option('force') && ! $this->confirm('Continue and replace it? A backup is kept.', $e->relation === 'upgrade')) {
                    $this->line('Cancelled.');

                    return self::FAILURE;
                }
                $result = $installer->install($source, true);
            }
        } catch (PluginInstallException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            if ($temp) {
                @unlink($temp);
            }
        }
        Activity::log('plugin.'.$result['action'], null, ucfirst($result['action'])." plugin {$result['slug']} {$result['version']} (CLI)", ['from' => $result['previous'], 'to' => $result['version']]);
        $this->info("{$result['name']} {$result['version']} {$result['action']} in {$result['path']}.".($result['backup'] ? " Backup: {$result['backup']}" : ''));
        foreach ($result['warnings'] ?? [] as $warning) {
            $this->warn($warning);
        }
        if ($result['action'] === 'installed') {
            $this->line("Run \"php artisan plugin enable {$result['slug']}\" to turn it on.");
        }

        return self::SUCCESS;
    }

    private function remove(PluginInstaller $installer, PluginManager $plugins, string $slug): int
    {
        if (! $this->option('force') && ! $this->confirm("Delete plugin $slug and its files?".($this->option('drop-settings') ? ' Its settings will be erased too.' : ''))) {
            $this->line('Cancelled.');

            return self::FAILURE;
        }
        try {
            $installer->remove($slug, (bool) $this->option('drop-settings'));
        } catch (PluginInstallException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        Activity::log('plugin.removed', null, "Removed plugin $slug (CLI)");
        $this->info("Plugin $slug removed.");

        return self::SUCCESS;
    }
}
