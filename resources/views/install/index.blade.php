@extends('layouts.auth')
@section('title', 'Install')
@section('wide', '1')
@section('content')
<div><h1>Install Tidemark Billing</h1><p class="auth-sub">Two minutes: check the server, connect a database, create your admin account.</p></div>
<ul class="checks">@foreach ($checks as $c)<li class="{{ $c['ok'] ? 'ok' : 'bad' }}" title="{{ $c['ok'] ? '' : $c['hint'] }}"><span>{{ $c['ok'] ? '✓' : '✕' }}</span>{{ $c['label'] }}</li>@endforeach</ul>
@if (! $ready)<div class="notice warn"><x-icon name="triangle-alert" :size="16" /><div>Fix the items marked ✕, then reload this page.</div></div>@else
<form method="POST" action="{{ route('install.store') }}" class="stack">
    @csrf
    <h2>Database</h2>
    <x-field name="db_driver" label="Type" type="select" :options="$drivers" data-reveal="db" />
    <div class="grid2" data-reveal-group="db" data-reveal-for="mysql">
        <x-field name="db_host" label="Host" value="127.0.0.1" /><x-field name="db_port" label="Port" value="3306" />
        <x-field name="db_database" label="Database name" /><x-field name="db_username" label="Username" />
        <x-field name="db_password" label="Password" type="password" class="span2" />
    </div>
    <p class="help" data-reveal-group="db" data-reveal-for="sqlite">SQLite stores everything in <code>database/database.sqlite</code>. Fine for most small businesses; pick MySQL for heavier use.</p>
    <hr>
    <h2>Your business</h2>
    <div class="grid2">
        <x-field name="company" label="Business name" required />
        <x-field name="currency" label="Default currency" type="select" :options="$currencies" value="USD" />
    </div>
    <h2>Administrator</h2>
    <div class="grid2">
        <x-field name="name" label="Your name" required /><x-field name="email" label="Email" type="email" required />
        <x-field name="password" label="Password" type="password" required autocomplete="new-password" /><x-field name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />
    </div>
    @if ($plugins)
        <hr><h2>Plugins</h2><p class="help">Switch on what you need now; you can change this any time under Plugins.</p>
        <div class="stack sm">@foreach ($plugins as $slug => $m)<x-field :name="'plugins['.$slug.']'" :label="$m['name'] ?? $slug" type="checkbox" :help="$m['description'] ?? null" />@endforeach</div>
    @endif
    <button class="btn primary" type="submit">Install</button>
</form>
@endif
@endsection
