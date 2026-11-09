@extends('layouts.app', ['area' => 'admin'])
@php($editing = $server->exists)
@section('title', $editing ? 'Edit '.$server->name : 'Add server')
@section('content')
<x-page-head :title="$editing ? $server->name : 'Add server'" :back="route('admin.servers.index')" backLabel="Servers">
    @if ($editing && auth()->user()->isAdmin())<form method="POST" action="{{ route('admin.servers.destroy', $server) }}" data-confirm="Delete {{ $server->name }}?" data-confirm-label="Delete" data-danger>@csrf @method('DELETE')<button class="btn danger" type="submit">Delete</button></form>@endif
</x-page-head>
@if (! $modules)<div class="notice warn"><x-icon name="triangle-alert" :size="16" /><div>No enabled plugin provides a server-based provisioning module. Enable one under <a href="{{ Route::has('admin.plugins.index') ? route('admin.plugins.index') : '#' }}" style="text-decoration:underline">Plugins</a> first.</div></div>@else
<form method="POST" action="{{ $editing ? route('admin.servers.update', $server) : route('admin.servers.store') }}" class="stack">
    @csrf @if ($editing) @method('PUT') @endif
    <x-card title="Connection">
        <div class="grid2">
            <x-field name="module" label="Module" type="select" :options="collect($modules)->map->label()->all()" :value="$server->module" data-reveal="module" />
            <x-field name="name" label="Name" :value="$server->name" required />
            <x-field name="hostname" label="Hostname" :value="$server->hostname" required placeholder="server1.example.com" />
            <x-field name="port" label="Port" type="number" :value="$server->port" help="Blank uses the module's default." />
            <x-field name="username" label="Username" :value="$server->username" />
            <x-field name="secret" label="Password / API token" type="password" placeholder="{{ $editing && $server->secret ? '•••••••• (saved, leave blank to keep)' : '' }}" />
        </div>
        <div class="grid3" style="margin-top:16px">
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
        <div class="grid2"><x-field name="max_services" label="Max services" type="number" min="1" :value="$server->max_services" help="Blank means unlimited." /></div>
        <div style="margin-top:16px"><x-field name="notes" label="Notes" type="textarea" :value="$server->notes" /></div>
    </x-card>
    <div class="row"><button class="btn primary" type="submit">{{ $editing ? 'Save changes' : 'Add server' }}</button><a class="btn" href="{{ route('admin.servers.index') }}">Cancel</a></div>
</form>
@endif
@endsection
