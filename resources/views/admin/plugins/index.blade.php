@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Plugins')
@section('content')
<x-page-head title="Plugins" sub="Payment gateways, provisioning and licensing. Enable a plugin to switch its features on." />
@unless (config('billing.hosted'))
<div class="notice warn mb-16"><x-icon name="triangle-alert" :size="16" /><div><strong>Plugins run arbitrary PHP with full access to your billing data, files and database.</strong> Only install plugins from sources you trust, and review code you did not write.</div></div>
@endunless

<x-view-tabs :tabs="['all' => 'All', 'enabled' => 'Enabled', 'disabled' => 'Disabled', 'updates' => 'Updates pending']" :current="$view" :counts="$counts" label="Plugin views" />

@if ($plugins->isEmpty())
    <x-card><x-empty icon="puzzle" :title="$view === 'all' ? 'No plugins found' : 'No plugins in this view'" :text="$view === 'all' ? (config('billing.hosted') ? 'No plugins are available on this install.' : 'Plugins are folders inside '.$path.'. Upload one below and refresh this page.') : 'Nothing matches this view.'">
        @if ($view !== 'all')<a class="btn" href="{{ route('admin.plugins.index') }}">Show all plugins</a>@endif
    </x-empty></x-card>
@else
<div class="plugin-grid">
    @foreach ($plugins as $slug => $p)
        @php($name = $p['name'] ?? $slug)
        <section class="card plugin-card {{ $p['enabled'] ? '' : 'off' }}" aria-labelledby="plugin-{{ $slug }}">
            <div class="plugin-top">
                <div class="plugin-ico"><x-icon :name="$p['icon'] ?? 'puzzle'" :size="18" /></div>
                <div>
                    <h3 id="plugin-{{ $slug }}">{{ $name }}</h3>
                    <div class="plugin-meta">
                        @if ($p['pending_update'])<span class="pill warn">Update pending</span>
                        @elseif ($p['enabled'])<span class="pill good">Enabled</span>
                        @else<span class="pill">Disabled</span>@endif
                        @if (! empty($p['version']))<span class="pill outline mono">v{{ ltrim($p['version'], 'v') }}</span>@endif
                        <span class="muted small">{{ ($p['origin'] ?? 'bundled') === 'uploaded' ? 'Uploaded' : 'Bundled' }}</span>
                    </div>
                </div>
            </div>
            @if (! empty($p['description']))<p class="muted">{{ $p['description'] }}</p>@endif
            @if ($p['pending_update'])<p class="small">Files are version {{ $p['version'] }} but version {{ $p['pending_update'] }} is recorded. Apply the update to run its migrations.</p>@endif
            <details>
                <summary>Details</summary>
                <div class="stack sm mt-8">
                    <p class="muted small">{{ $slug }}@if(!empty($p['author'])) · by {{ $p['author'] }}@endif @if(!empty($p['homepage']) && preg_match('#^https?://#', $p['homepage'])) · <a class="link-underline" href="{{ $p['homepage'] }}" target="_blank" rel="noopener noreferrer">website</a>@endif</p>
                    <p class="muted small mono break-all">{{ $p['path'] }}</p>
                    @if (! $p['enabled'] && $canUpload && $p['deletable'])
                        <form method="POST" action="{{ route('admin.plugins.destroy', $slug) }}" class="stack sm" data-confirm="Delete {{ $name }}? Its uninstall hook runs and its files are removed (a backup copy is kept in storage/app/plugin-backups). Tick the box first to also erase its saved settings." data-confirm-label="Delete" data-danger>@csrf @method('DELETE')
                            <label class="check small"><input type="checkbox" name="drop_settings" value="1"> Also delete its saved settings</label>
                            <button class="btn sm danger" type="submit">Delete plugin</button>
                        </form>
                    @elseif (! $p['deletable'])
                        <p class="muted small">Bundled plugins can be disabled but not deleted from here.</p>
                    @else
                        <p class="muted small">Disable this plugin before deleting it.</p>
                    @endif
                </div>
            </details>
            <div class="plugin-foot">
                @if ($p['enabled'])
                    <div class="row">
                        @if ($p['pending_update'])<form method="POST" action="{{ route('admin.plugins.enable', $slug) }}">@csrf<button class="btn sm primary" type="submit">Apply update</button></form>@endif
                        @if ($p['configurable'])<a class="btn sm" href="{{ route('admin.plugins.settings', $slug) }}"><x-icon name="settings" :size="14" /> Settings</a>@else<span class="muted small">No settings</span>@endif
                    </div>
                    <form method="POST" action="{{ route('admin.plugins.disable', $slug) }}" data-confirm="Disable {{ $name }}? Its data is kept, but its features stop working until you enable it again." data-confirm-label="Disable">@csrf<button class="btn sm" type="submit">Disable</button></form>
                @else
                    <span class="muted small">Off. Its data is kept.</span>
                    <form method="POST" action="{{ route('admin.plugins.enable', $slug) }}">@csrf<button class="btn sm primary" type="submit">Enable</button></form>
                @endif
            </div>
        </section>
    @endforeach
</div>
@endif

@if ($canUpload)
<form class="card setting mt-24" method="POST" action="{{ route('admin.plugins.upload') }}" enctype="multipart/form-data">
    @csrf
    <div class="setting-h"><h2>Install or upgrade a plugin</h2><p>Upload a plugin .zip. Uploading a newer version of an installed plugin upgrades it and keeps its settings.</p></div>
    <div class="setting-b">
    @if (! $zip)
        <p class="muted m-0">The PHP <code>zip</code> extension is not available on this server, so archives cannot be unpacked here. Unzip the plugin so that <code>plugin.json</code> is in <code>{{ $path }}/&lt;slug&gt;/</code> (upload by FTP or file manager), then press Enable. Or enable <code>ext-zip</code>, or run <code>php artisan plugin install file.zip</code> elsewhere.</p>
    @else
        @unless ($writable)<div class="notice warn"><x-icon name="triangle-alert" :size="16" /><div>{{ $path }} is not writable by the web server, so uploads will fail. Make it writable (for example <code>chmod -R u+rwX {{ $path }}</code>), set <code>BILLING_EXTRA_PLUGINS_PATH</code> to a writable folder, or install by FTP.</div></div>@endunless
        <div class="sf"><label for="plugin-zip" class="sf-label"><span>Plugin file</span></label>
            <input id="plugin-zip" type="file" name="plugin" accept=".zip,application/zip" required aria-describedby="plugin-zip-help">
            <span class="help" id="plugin-zip-help">.zip up to {{ $maxMb }} MB, with <code>plugin.json</code> at the top (or inside one top-level folder, like a GitHub download). Installs into <code>{{ $path }}</code>.</span></div>
    @endif
    </div>
    @if ($zip)<div class="setting-f"><span class="help">You confirm before an existing plugin is replaced.</span><button class="btn sm primary" type="submit"><x-icon name="upload" :size="14" /> Upload and install</button></div>@endif
</form>
@elseif (! $uploadEnabled && ! config('billing.hosted'))
<p class="help mt-16">Uploading and deleting plugins is turned off (<code>BILLING_ALLOW_PLUGIN_UPLOAD=false</code>). Put plugin folders in <code>{{ $path }}</code> by hand and press Enable.</p>
@endif
@unless (config('billing.hosted'))
<p class="help mt-16">Building your own? Each plugin is a folder with a <code>plugin.json</code> manifest in <code>{{ $path }}</code>.</p>
@endunless
@endsection
