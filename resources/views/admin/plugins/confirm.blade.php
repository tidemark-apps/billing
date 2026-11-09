@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Confirm plugin '.$c->relation)
@section('content')
<x-page-head :title="'Confirm plugin '.($c->relation === 'upgrade' ? 'upgrade' : ($c->relation === 'same' ? 'reinstall' : 'downgrade'))" :back="route('admin.plugins.index')" back-label="Plugins" />
<section class="card pad stack" style="max-width:720px">
    <h3>{{ $c->name }}: <span class="mono">{{ $c->from }}</span> → <span class="mono">{{ $c->to }}</span></h3>
    @if ($c->relation === 'upgrade')
        <p class="muted">This upgrades the installed plugin. Its settings and enabled state are kept, the current folder is backed up to <code>storage/app/plugin-backups</code>, and the new version's migrations run if the plugin is enabled. If a migration fails, the migrations that already ran are rolled back (through their down()) and the old files are restored.</p>
    @elseif ($c->relation === 'same')
        <p class="muted">The same version is already installed. Reinstalling replaces its files with the uploaded ones (a backup is made first). Settings and enabled state are kept.</p>
    @else
        <p class="muted"><strong>This is an older version than the one installed.</strong> Downgrading replaces the files with the older code (a backup is made first). Database changes made by newer migrations are not reverted, so the older code may not work with them.</p>
    @endif
    @if ($c->bundled)
        <div class="notice warn"><x-icon name="triangle-alert" :size="16" /><div>{{ $c->name }} ships with Tidemark Billing (or was copied in by hand). Continuing overrides the bundled copy{{ config('billing.plugins_extra_path') ? ' with the uploaded one (the bundled folder is left untouched)' : ' (the bundled folder is replaced; a release update may overwrite your version again)' }}.</div></div>
    @endif
    <div class="notice warn"><x-icon name="triangle-alert" :size="16" /><div>Plugins run arbitrary PHP with full access to your data. Only continue if you trust where this file came from.</div></div>
    <form method="POST" action="{{ route('admin.plugins.upload.install', $token) }}" class="row" style="gap:8px">@csrf
        <button class="btn primary" type="submit">{{ $c->relation === 'upgrade' ? 'Upgrade' : ($c->relation === 'same' ? 'Reinstall' : 'Downgrade') }} to {{ $c->to }}</button>
        <a class="btn" href="{{ route('admin.plugins.index') }}">Cancel</a>
    </form>
</section>
@endsection
