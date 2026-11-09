@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Servers')
@section('content')
<x-page-head title="Servers" sub="Control panels and hosts your provisioning modules talk to."><a class="btn primary" href="{{ route('admin.servers.create') }}"><x-icon name="plus" :size="15" /> Add server</a></x-page-head>
<x-card :flush="true">
    @if ($servers->isEmpty())<x-empty icon="server" title="No servers yet" text="Enable a provisioning plugin (cPanel, DirectAdmin, Enhance) under Plugins, then add its server here."><a class="btn primary sm" href="{{ route('admin.servers.create') }}">Add server</a></x-empty>@else
    <table class="t stack-sm"><thead><tr><th>Server</th><th>Module</th><th>Hostname</th><th class="right">Services</th><th>Status</th><th></th></tr></thead><tbody>
    @foreach ($servers as $s)
        <tr><td data-label="Server"><a href="{{ route('admin.servers.edit', $s) }}"><b>{{ $s->name }}</b></a></td><td data-label="Module">{{ $modules[$s->module] ?? $s->module }}</td><td data-label="Hostname" class="mono">{{ $s->hostname }}</td>
        <td class="right" data-label="Services">{{ $s->live_count }}{{ $s->max_services ? ' / '.$s->max_services : '' }}</td>
        <td data-label="Status"><span class="pill {{ $s->is_active ? 'good' : '' }}">{{ $s->is_active ? 'Active' : 'Disabled' }}</span></td>
        <td class="right"><form method="POST" action="{{ route('admin.servers.test', $s) }}">@csrf<button class="btn sm" type="submit" data-no-lock>Test connection</button></form></td></tr>
    @endforeach</tbody></table>@endif
</x-card>
@endsection
