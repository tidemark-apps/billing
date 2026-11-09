<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Support\FieldSchema;
use App\Plugins\PluginManager;
use App\Support\Fields;
use App\Support\Settings;
use Illuminate\Http\Request;

/**
 * Installed plugins: state, enable/disable and their settings (secrets write-only). Installing (uploading) a plugin is a
 * separate feature. Enabling, disabling and configuring need an administrator, like the admin screens.
 */
class PluginController extends ApiController
{
    public function __construct(private readonly PluginManager $plugins) {}

    public function index()
    {
        $states = $this->plugins->states();

        return response()->json(['data' => collect($this->plugins->discover())->map(fn ($m, $slug) => $this->present($slug, $m, $states))->values()]);
    }

    public function show(string $slug)
    {
        $manifest = $this->plugins->manifest($slug) ?? abort(404);

        return response()->json(['data' => $this->present($slug, $manifest, $this->plugins->states())]);
    }

    public function enable(Request $request, string $slug)
    {
        $this->requireAdmin($request, 'Only administrators can enable plugins.');
        $manifest = $this->plugins->manifest($slug) ?? abort(404);
        try {
            $this->plugins->enable($slug);
        } catch (\Throwable $e) {
            throw new \DomainException('Could not enable the plugin: '.$e->getMessage());
        }
        $this->audit('plugin.enabled', null, "Enabled plugin $slug");

        return response()->json(['data' => $this->present($slug, $manifest, $this->plugins->states())]);
    }

    public function disable(Request $request, string $slug)
    {
        $this->requireAdmin($request, 'Only administrators can disable plugins.');
        $manifest = $this->plugins->manifest($slug) ?? abort(404);
        $this->plugins->disable($slug);
        $this->audit('plugin.disabled', null, "Disabled plugin $slug");

        return response()->json(['data' => $this->present($slug, $manifest, $this->plugins->states())]);
    }

    public function settings(string $slug)
    {
        return response()->json(['data' => $this->presentSettings($slug)]);
    }

    /** {"settings": {"api_key": "...", "mode": "live"}}: partial; a blank secret keeps the stored one. */
    public function updateSettings(Request $request, string $slug)
    {
        $this->requireAdmin($request, 'Only administrators can change plugin settings.');
        $plugin = $this->enabledPlugin($slug);
        $fields = $plugin->fields();
        $sent = $request->input('settings');
        FieldSchema::rejectUnknown($fields, $sent, 'settings');
        $request->validate(Fields::rules(array_values(array_filter($fields, fn ($f) => array_key_exists($f['key'], $sent))), 'settings.'));

        $secrets = Fields::secretKeys($fields);
        foreach (FieldSchema::collectPresent($fields, $sent) as $key => $value) {
            if ($value === null || $value === '') {
                Settings::forget("plugin.$slug.$key");
            } else {
                Settings::set("plugin.$slug.$key", is_bool($value) ? ($value ? 'true' : 'false') : $value, in_array($key, $secrets, true));
            }
        }
        $this->audit('plugin.configured', null, "Updated settings for plugin $slug");

        return response()->json(['data' => $this->presentSettings($slug)]);
    }

    /** @param  array<string, mixed>  $manifest @param  array<string, bool>  $states @return array<string, mixed> */
    private function present(string $slug, array $manifest, array $states): array
    {
        return [
            'slug' => $slug,
            'name' => $manifest['name'] ?? $slug,
            'version' => $manifest['version'] ?? null,
            'description' => $manifest['description'] ?? null,
            'author' => $manifest['author'] ?? null,
            'origin' => $manifest['origin'] ?? null,
            'enabled' => $states[$slug] ?? false,
            'configurable' => (bool) $this->plugins->instance($slug)?->fields(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentSettings(string $slug): array
    {
        $plugin = $this->enabledPlugin($slug);

        return ['slug' => $slug, 'fields' => FieldSchema::describe($plugin->fields(), fn ($key) => Settings::get("plugin.$slug.$key"))];
    }

    private function enabledPlugin(string $slug)
    {
        $plugin = $this->plugins->instance($slug);
        abort_unless($plugin && $this->plugins->isEnabled($slug), 404);

        return $plugin;
    }
}
