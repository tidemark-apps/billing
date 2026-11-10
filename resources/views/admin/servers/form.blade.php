@extends('layouts.app', ['area' => 'admin'])
@php($editing = $server->exists)
@section('title', $editing ? 'Edit '.$server->name : 'Add server')
@section('content')
<x-page-head :title="$editing ? $server->name : 'Add server'" :sub="$editing ? $server->hostname : 'Connect a control panel your provisioning modules can use.'" :back="route('admin.servers.index')" backLabel="Servers" />
@if (! $modules)<div class="notice warn"><x-icon name="triangle-alert" :size="16" /><div>No enabled plugin provides a server-based provisioning module. Enable one under <a href="{{ Route::has('admin.plugins.index') ? route('admin.plugins.index') : '#' }}" style="text-decoration:underline">Plugins</a> first.</div></div>@else
<form method="POST" action="{{ $editing ? route('admin.servers.update', $server) : route('admin.servers.store') }}" class="stack form-narrow" data-validate>
    @csrf @if ($editing) @method('PUT') @endif
    <x-card title="Connection">
        <div class="grid2">
            <x-field name="module" label="Module" type="select" :options="collect($modules)->map->label()->all()" :value="$server->module" data-reveal="module" />
            <x-field name="name" aria-required="true" label="Name" :value="$server->name" />
            <x-field name="hostname" aria-required="true" label="Hostname" :value="$server->hostname" placeholder="server1.example.com" />
            <x-field name="port" label="Port (optional)" type="number" :value="$server->port" help="Blank uses the module's default." />
            <x-field name="username" label="Username (optional)" :value="$server->username" />
            <x-field name="secret" label="Password / API token (optional)" type="password" placeholder="{{ $editing && $server->secret ? '•••••••• (saved, leave blank to keep)' : '' }}" />
        </div>
        <div class="stack sm mt-16">
            <x-field name="use_ssl" type="checkbox" label="Use HTTPS" :value="$server->use_ssl" />
            <x-field name="verify_ssl" type="checkbox" label="Verify certificate" help="Turn off only for self-signed certificates." :value="$server->verify_ssl" />
            <x-field name="is_active" type="checkbox" label="Active" help="Eligible for new services." :value="$server->is_active" />
        </div>
    </x-card>
    @foreach ($modules as $key => $m)
        @if ($m->serverFields())
        <div class="hidden" data-reveal-group="module" data-reveal-for="{{ $key }}"><x-card title="{{ $m->label() }} options"><div class="stack"><x-schema-fields :fields="$m->serverFields()" :values="$server->module === $key ? ($server->config ?? []) : []" prefix="config" /></div></x-card></div>
        @endif
    @endforeach
    <x-card title="Capacity &amp; notes">
        <div class="grid2"><x-field name="max_services" label="Max services (optional)" type="number" min="1" :value="$server->max_services" help="Blank means unlimited." /></div>
        <div class="mt-16"><x-field name="notes" label="Notes (optional)" type="textarea" :value="$server->notes" /></div>
    </x-card>
    <div class="row"><button class="btn primary" type="submit">{{ $editing ? 'Save changes' : 'Add server' }}</button><a class="btn" href="{{ route('admin.servers.index') }}">Cancel</a></div>
</form>
@if ($editing && auth()->user()->isAdmin())
<form method="POST" action="{{ route('admin.servers.destroy', $server) }}" class="card setting danger-zone form-narrow mt-16" data-confirm="Delete {{ $server->name }}? Services must be moved or terminated first." data-confirm-label="Delete server" data-danger>@csrf @method('DELETE')
    <div class="setting-h"><h2>Danger zone</h2><p>A server that still hosts services can't be deleted. Mark it inactive instead to stop new services landing on it.</p></div>
    <div class="setting-f"><span class="help">Delete {{ $server->name }}</span><button class="btn danger sm" type="submit">Delete server</button></div>
</form>
@endif
@endif
@endsection
