@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Plugins')
@section('content')
<x-page-head title="Plugins" sub="Payment gateways, provisioning and licensing. Upload a plugin .zip or drop its folder in to enable more." />
<div class="notice warn" style="margin-bottom:16px"><x-icon name="triangle-alert" :size="16" /><div><strong>Plugins run arbitrary PHP with full access to your billing data, files and database.</strong> Only install plugins from sources you trust, and review code you did not write.</div></div>
@if ($canUpload)
<section class="card pad" style="margin-bottom:16px">
    <h3 style="font-size:15px">Install or upgrade a plugin</h3>
    @if (! $zip)
        <p class="muted" style="margin-top:6px">The PHP <code>zip</code> extension is not available on this server, so archives cannot be unpacked here. Unzip the plugin so that <code>plugin.json</code> is in <code>{{ $path }}/&lt;slug&gt;/</code> (upload by FTP or file manager), then press Enable. Or enable <code>ext-zip</code>, or run <code>php artisan plugin install file.zip</code> elsewhere.</p>
    @else
        @unless ($writable)<div class="notice warn" style="margin-top:8px"><x-icon name="triangle-alert" :size="16" /><div>{{ $path }} is not writable by the web server, so uploads will fail. Make it writable (e.g. <code>chmod -R u+rwX {{ $path }}</code>, or 0755/0775 in your file manager), set <code>BILLING_EXTRA_PLUGINS_PATH</code> to a writable folder, or install by FTP.</div></div>@endunless
        <form method="POST" action="{{ route('admin.plugins.upload') }}" enctype="multipart/form-data" class="row wrap" style="gap:10px;margin-top:10px;align-items:center">@csrf
            <input type="file" name="plugin" accept=".zip,application/zip" required>
            <button class="btn primary" type="submit"><x-icon name="upload" :size="14" /> Upload</button>
        </form>
        <p class="help" style="margin-top:8px">.zip up to {{ $maxMb }} MB, with <code>plugin.json</code> at the top (or inside one top-level folder, like a GitHub download). Installs into <code>{{ $path }}</code>. Uploading a newer version of an installed plugin upgrades it and keeps its settings.</p>
    @endif
</section>
@elseif (! $uploadEnabled)
<p class="help" style="margin-bottom:16px">Uploading and deleting plugins is turned off (<code>BILLING_ALLOW_PLUGIN_UPLOAD=false</code>). Put plugin folders in <code>{{ $path }}</code> by hand and press Enable.</p>
@endif
@if ($plugins->isEmpty())
    <x-card><x-empty icon="puzzle" title="No plugins found" :text="'Plugins are folders inside '.$path.'. Upload one and refresh this page.'" /></x-card>
@else
<div class="stack">
    @foreach ($plugins as $slug => $p)
        <section class="card pad">
            <div class="row spread wrap" style="align-items:flex-start;gap:16px">
                <div class="row" style="align-items:flex-start;gap:14px;min-width:0">
                    <div class="empty" style="padding:0"><div class="ico" style="margin:0"><x-icon :name="$p['icon'] ?? 'puzzle'" :size="20" /></div></div>
                    <div style="min-width:0">
                        <div class="row" style="gap:8px"><h3 style="font-size:16px">{{ $p['name'] ?? $slug }}</h3><span class="pill outline mono">{{ $p['version'] ?? '' }}</span>@if($p['enabled'])<span class="pill good">Enabled</span>@endif @if($p['pending_update'])<span class="pill warn">Update pending</span>@endif <span class="pill outline">{{ ($p['origin'] ?? 'bundled') === 'uploaded' ? 'Uploaded' : 'Bundled' }}</span></div>
                        <p class="muted" style="margin-top:4px;max-width:70ch">{{ $p['description'] ?? '' }}</p>
                        <p class="muted small" style="margin-top:4px">{{ $slug }}@if(!empty($p['author'])) · by {{ $p['author'] }}@endif @if(!empty($p['homepage']) && preg_match('#^https?://#', $p['homepage'])) · <a href="{{ $p['homepage'] }}" target="_blank" rel="noopener noreferrer" style="text-decoration:underline">website</a>@endif</p>
                        <p class="muted small mono" style="margin-top:2px;word-break:break-all">{{ $p['path'] }}</p>
                        @if($p['pending_update'])<p class="small" style="margin-top:4px">Files are version {{ $p['version'] }} but version {{ $p['pending_update'] }} is recorded. Apply the update to run its migrations.</p>@endif
                    </div>
                </div>
                <div class="row">
                    @if ($p['enabled'])
                        @if ($p['pending_update'])<form method="POST" action="{{ route('admin.plugins.enable', $slug) }}">@csrf<button class="btn sm primary" type="submit">Apply update</button></form>@endif
                        @if ($p['configurable'])<a class="btn sm" href="{{ route('admin.plugins.settings', $slug) }}"><x-icon name="settings" :size="14" /> Settings</a>@endif
                        <form method="POST" action="{{ route('admin.plugins.disable', $slug) }}" data-confirm="Disable {{ $p['name'] ?? $slug }}? Its data is kept, but its features stop working until you enable it again.">@csrf<button class="btn sm" type="submit">Disable</button></form>
                    @else
                        <form method="POST" action="{{ route('admin.plugins.enable', $slug) }}">@csrf<button class="btn sm primary" type="submit">Enable</button></form>
                        @if ($canUpload && $p['deletable'])
                            <form method="POST" action="{{ route('admin.plugins.destroy', $slug) }}" data-confirm="Delete {{ $p['name'] ?? $slug }}? Its uninstall hook runs and its files are removed (a backup copy is kept in storage/app/plugin-backups). Tick the box first to also erase its saved settings." data-confirm-label="Delete" data-danger>@csrf @method('DELETE')
                                <label class="small muted" style="margin-right:6px"><input type="checkbox" name="drop_settings" value="1"> also delete settings</label>
                                <button class="btn sm danger" type="submit">Delete</button>
                            </form>
                        @elseif (! $p['deletable'])
                            <span class="muted small" title="Bundled plugins can be disabled but not deleted from the UI.">Bundled: disable only</span>
                        @endif
                    @endif
                </div>
            </div>
        </section>
    @endforeach
</div>
@endif
<p class="help" style="margin-top:16px">Plugin folder: <code>{{ $path }}</code>. Building your own? Each plugin is a folder with a <code>plugin.json</code> manifest.</p>
@endsection
