<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PluginRecord;
use App\Plugins\PluginConfirmationRequired;
use App\Plugins\PluginInstaller;
use App\Plugins\PluginInstallException;
use App\Plugins\PluginManager;
use App\Support\Activity;
use App\Support\Fields;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class PluginController extends Controller
{
    public function __construct(private readonly PluginManager $plugins, private readonly PluginInstaller $installer) {}

    public function index()
    {
        $states = $this->plugins->states();
        $this->recordedVersions = PluginRecord::pluck('version', 'slug')->all();
        $items = collect($this->plugins->discover())->map(function (array $m, string $slug) use ($states) {
            $recorded = $this->recordedVersions[$slug] ?? null;

            return $m + [
                'enabled' => $states[$slug] ?? false,
                'configurable' => (bool) $this->plugins->instance($slug)?->fields(),
                'pending_update' => ($states[$slug] ?? false) && $recorded !== null && version_compare((string) $recorded, (string) ($m['version'] ?? '0'), '<') ? $recorded : null,
                'deletable' => ($m['origin'] ?? 'bundled') === 'uploaded',
            ];
        });

        $counts = [
            'all' => $items->count(),
            'enabled' => $items->where('enabled', true)->count(),
            'disabled' => $items->where('enabled', false)->count(),
            'updates' => $items->filter(fn (array $p) => (bool) $p['pending_update'])->count(),
        ];
        $view = in_array(request()->query('view'), ['enabled', 'disabled', 'updates'], true) ? request()->query('view') : 'all';
        $shown = $items->filter(fn (array $p) => match ($view) {
            'enabled' => $p['enabled'],
            'disabled' => ! $p['enabled'],
            'updates' => (bool) $p['pending_update'],
            default => true,
        });

        return view('admin.plugins.index', [
            'counts' => $counts,
            'view' => $view,
            'plugins' => $shown,
            'path' => $this->installer->targetRoot(),
            'canUpload' => PluginInstaller::uploadEnabled() && request()->user()->isAdmin(),
            'uploadEnabled' => PluginInstaller::uploadEnabled(),
            'zip' => PluginInstaller::zipAvailable(),
            'maxMb' => (int) config('billing.plugin_upload.max_mb', 16),
            'writable' => $this->installer->targetRootWritable(),
        ]);
    }

    /** @var array<string, string> */
    private array $recordedVersions = [];

    public function upload(Request $request): RedirectResponse
    {
        $this->guardUpload($request);
        $file = $request->file('plugin');
        if (! $file || ! $file->isValid()) {
            return back()->with('error', $file ? 'The upload failed: '.$file->getErrorMessage().' (check upload_max_filesize / post_max_size in php.ini).' : 'Choose a .zip file to upload.');
        }
        if (strtolower($file->getClientOriginalExtension()) !== 'zip') {
            return back()->with('error', 'Plugins are uploaded as a .zip archive.');
        }

        try {
            $result = $this->installer->install($file->getRealPath());
        } catch (PluginConfirmationRequired $e) {
            $token = bin2hex(random_bytes(16));
            $dir = storage_path('app/plugin-uploads');
            File::ensureDirectoryExists($dir);
            $this->pruneStash($dir);
            $file->move($dir, $token.'.zip');

            return redirect()->route('admin.plugins.upload.confirm', $token);
        } catch (PluginInstallException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->done($result);
    }

    public function confirm(Request $request, string $token)
    {
        $this->guardUpload($request);
        $zip = $this->stashed($token);
        try {
            $c = $this->installer->preview($zip);
        } catch (PluginInstallException $e) {
            @unlink($zip);

            return redirect()->route('admin.plugins.index')->with('error', $e->getMessage());
        }
        if (! $c) {
            @unlink($zip);

            return redirect()->route('admin.plugins.index')->with('error', 'The plugin is no longer installed, so there is nothing to confirm. Upload it again to install it.');
        }

        return view('admin.plugins.confirm', ['c' => $c, 'token' => $token]);
    }

    public function install(Request $request, string $token): RedirectResponse
    {
        $this->guardUpload($request);
        $zip = $this->stashed($token);
        try {
            $result = $this->installer->install($zip, true);
        } catch (PluginInstallException $e) {
            @unlink($zip);

            return redirect()->route('admin.plugins.index')->with('error', $e->getMessage());
        }
        @unlink($zip);

        return $this->done($result);
    }

    public function destroy(Request $request, string $slug): RedirectResponse
    {
        $this->guardUpload($request);
        abort_unless($manifest = $this->plugins->manifest($slug), 404);
        try {
            $this->installer->remove($slug, $request->boolean('drop_settings'));
        } catch (PluginInstallException $e) {
            return back()->with('error', $e->getMessage());
        }
        Activity::log('plugin.removed', null, "Removed plugin $slug", ['version' => $manifest['version'] ?? null, 'settings_dropped' => $request->boolean('drop_settings')]);

        return redirect()->route('admin.plugins.index')->with('status', ($manifest['name'] ?? $slug).' was deleted.');
    }

    private function guardUpload(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only administrators can install or remove plugins.');
        abort_unless(PluginInstaller::uploadEnabled(), 403, 'Plugin uploads are disabled (BILLING_ALLOW_PLUGIN_UPLOAD=false).');
    }

    private function stashed(string $token): string
    {
        $file = storage_path('app/plugin-uploads/'.$token.'.zip');
        abort_unless(is_file($file), 404, 'That upload has expired. Upload the plugin again.');

        return $file;
    }

    private function pruneStash(string $dir): void
    {
        foreach (glob($dir.'/*.zip') ?: [] as $old) {
            if (filemtime($old) < time() - 3600) {
                @unlink($old);
            }
        }
    }

    /** @param  array<string, mixed>  $r */
    private function done(array $r): RedirectResponse
    {
        Activity::log('plugin.'.$r['action'], null, ucfirst($r['action'])." plugin {$r['slug']} {$r['version']}", ['slug' => $r['slug'], 'from' => $r['previous'], 'to' => $r['version'], 'backup' => $r['backup']]);
        $msg = "{$r['name']} {$r['version']} {$r['action']}.".($r['previous'] ? " (was {$r['previous']})" : '')
            .($r['migrated'] ? ' Migrations ran.' : '')
            .($r['action'] === 'installed' ? ' Press Enable to turn it on.' : '')
            .($r['warnings'] ? ' Warning: '.implode(' ', $r['warnings']) : '');

        return redirect()->route('admin.plugins.index')->with('status', $msg);
    }

    public function enable(Request $request, string $slug): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($this->plugins->manifest($slug), 404);
        try {
            $this->plugins->enable($slug);
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not enable the plugin: '.$e->getMessage());
        }
        Activity::log('plugin.enabled', null, "Enabled plugin $slug");

        return redirect()->route('admin.plugins.index')->with('status', ($this->plugins->manifest($slug)['name'] ?? $slug).' enabled.');
    }

    public function disable(Request $request, string $slug): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($this->plugins->manifest($slug), 404);
        $this->plugins->disable($slug);
        Activity::log('plugin.disabled', null, "Disabled plugin $slug");

        return redirect()->route('admin.plugins.index')->with('status', ($this->plugins->manifest($slug)['name'] ?? $slug).' disabled. Its data is kept.');
    }

    public function settings(string $slug)
    {
        $plugin = $this->plugins->instance($slug);
        abort_unless($plugin && $this->plugins->isEnabled($slug), 404);
        $values = [];
        foreach ($plugin->fields() as $f) {
            $values[$f['key']] = Settings::get("plugin.$slug.".$f['key']);
        }

        return view('admin.plugins.settings', ['plugin' => $plugin, 'fields' => $plugin->fields(), 'values' => $values]);
    }

    public function update(Request $request, string $slug): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $plugin = $this->plugins->instance($slug);
        abort_unless($plugin && $this->plugins->isEnabled($slug), 404);

        $fields = $plugin->fields();
        $request->validate(Fields::rules($fields, 'config.'));
        $secrets = Fields::secretKeys($fields);
        foreach (Fields::collect($fields, $request->input('config', [])) as $key => $value) {
            if ($value === null || $value === '') {
                Settings::forget("plugin.$slug.$key");
            } else {
                Settings::set("plugin.$slug.$key", is_bool($value) ? ($value ? 'true' : 'false') : $value, in_array($key, $secrets, true));
            }
        }
        Activity::log('plugin.configured', null, "Updated settings for plugin $slug");

        return back()->with('status', 'Plugin settings saved.');
    }
}
