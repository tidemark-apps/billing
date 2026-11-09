@extends('layouts.app', ['area' => 'admin'])
@section('title', $service->title())
@section('content')
@php($st = $service->status)
<x-page-head :title="$service->title()" :back="route('admin.services.index')" backLabel="Services">
    @if ($st === \App\Enums\ServiceStatus::Pending)<form method="POST" action="{{ route('admin.services.activate', $service) }}">@csrf<button class="btn primary" type="submit">Activate</button></form>@endif
    @if ($st === \App\Enums\ServiceStatus::Active)
        <form method="POST" action="{{ route('admin.services.suspend', $service) }}" data-confirm="Suspend this service?" data-confirm-label="Suspend">@csrf<button class="btn" type="submit">Suspend</button></form>
    @endif
    @if ($st === \App\Enums\ServiceStatus::Suspended)<form method="POST" action="{{ route('admin.services.unsuspend', $service) }}">@csrf<button class="btn" type="submit">Unsuspend</button></form>@endif
    @if ($module)@foreach ($module->adminActions() as $key => $label)<form method="POST" action="{{ route('admin.services.action', $service) }}">@csrf<input type="hidden" name="action" value="{{ $key }}"><button class="btn" type="submit">{{ $label }}</button></form>@endforeach @endif
    @if ($st->isLive() || $st === \App\Enums\ServiceStatus::Pending)
        <form method="POST" action="{{ route('admin.services.cancel', $service) }}" data-confirm="Cancel this service now? The remote account will be removed." data-confirm-label="Cancel service" data-danger>@csrf<input type="hidden" name="immediately" value="1"><button class="btn danger" type="submit">Cancel now</button></form>
        <form method="POST" action="{{ route('admin.services.terminate', $service) }}" data-confirm="Terminate this service? The remote account will be deleted." data-confirm-label="Terminate" data-danger>@csrf<button class="btn danger" type="submit">Terminate</button></form>
    @endif
</x-page-head>
@if ($service->provision_error)
    <div class="notice danger center"><x-icon name="triangle-alert" :size="16" /><div><b>Provisioning problem.</b> {{ $service->provision_error }}</div>
    @if ($st === \App\Enums\ServiceStatus::Pending)<form class="alert-action" method="POST" action="{{ route('admin.services.activate', $service) }}">@csrf<button class="btn sm" type="submit">Retry</button></form>@endif</div>
@endif
@if ($service->cancel_at_period_end)<div class="notice warn"><x-icon name="info" :size="16" /><div>Set to cancel on {{ $service->next_due_date?->format('j M Y') }}.</div></div>@endif
<div class="grid2">
    <x-card title="Summary"><dl class="kv wide">
        <dt>Status</dt><dd><x-status :status="$st" /></dd>
        <dt>Client</dt><dd><a href="{{ route('admin.clients.show', $service->client_id) }}" style="text-decoration:underline">{{ $service->client->displayName() }}</a></dd>
        <dt>Product</dt><dd>{{ $service->product?->name ?? '—' }}</dd>
        <dt>Module</dt><dd>{{ $module?->label() ?? 'None' }}</dd>
        @if ($service->server)<dt>Server</dt><dd>{{ $service->server->name }}</dd>@endif
        <dt>Price</dt><dd>{{ money($service->amount, $service->currency) }} {{ $service->billing_cycle->suffix() }}</dd>
        <dt>Next due</dt><dd>{{ $service->next_due_date?->format('j M Y') ?? '—' }}</dd>
        <dt>Activated</dt><dd>{{ $service->activated_at?->format('j M Y H:i') ?? '—' }}</dd>
    </dl></x-card>
    <x-card title="Account details">
        @if ($service->provision)<dl class="kv wide">@foreach ($service->provision as $k => $v)<dt>{{ str_replace('_', ' ', ucfirst($k)) }}</dt><dd>{{ is_scalar($v) ? $v : json_encode($v) }}</dd>@endforeach</dl>@else<span class="muted">Nothing stored by the module yet.</span>@endif
        @if ($service->secrets)
            <hr>
            @if ($reveal)
                <dl class="kv wide">@foreach ($service->secrets as $k => $v)<dt>{{ str_replace('_', ' ', ucfirst($k)) }}</dt><dd><span class="secret-box" style="display:inline-flex">{{ is_scalar($v) ? $v : json_encode($v) }}<button type="button" class="btn sm" data-copy="{{ is_scalar($v) ? $v : json_encode($v) }}">Copy</button></span></dd>@endforeach</dl>
            @else
                <form method="POST" action="{{ route('admin.services.reveal', $service) }}" class="row spread"><span class="muted small">{{ count($service->secrets) }} secret value(s) stored encrypted.</span>@csrf<button class="btn sm" type="submit"><x-icon name="eye" :size="14" /> Reveal</button></form>
            @endif
        @endif
    </x-card>
</div>
@include('admin.usage.panel', ['service' => $service])
{!! extensions('admin.service', ['service' => $service]) !!}
<form method="POST" action="{{ route('admin.services.update', $service) }}" class="card setting">@csrf @method('PUT')
    <div class="setting-h"><h2>Edit service</h2><p>Price changes apply from the next renewal invoice.</p></div>
    <div class="setting-b"><div class="grid3">
        <x-field name="label" label="Label" :value="$service->label" />
        <x-field name="amount" label="Recurring price ({{ $service->currency }})" type="number" step="0.01" min="0" :value="\App\Support\Money::toDecimal($service->amount, $service->currency)" required />
        <x-field name="billing_cycle" label="Billing cycle" type="select" :options="$cycles" :value="$service->billing_cycle->value" />
        <x-field name="next_due_date" label="Next due date" type="date" :value="$service->next_due_date?->toDateString()" />
        @if ($servers)<x-field name="server_id" label="Server" type="select" :options="['' => 'Automatic'] + $servers" :value="$service->server_id" />@endif
    </div><x-field name="notes" label="Internal notes" type="textarea" :value="$service->notes" /></div>
    <div class="setting-f"><span></span><button class="btn primary sm" type="submit">Save</button></div>
</form>
<x-card title="Activity log" :flush="true">
    @if ($service->logs->isEmpty())<x-empty icon="list" title="Nothing logged yet" />@else
    <div style="padding:4px 20px 12px">@foreach ($service->logs as $l)<div class="log-line"><span class="muted">{{ $l->created_at->format('j M Y H:i') }}</span><span><b>{{ $l->action }}</b> <span class="pill {{ $l->success ? 'good' : 'bad' }}">{{ $l->success ? 'OK' : 'Failed' }}</span>@if ($l->message)<div class="muted small">{{ $l->message }}</div>@endif @if ($l->user)<div class="muted small">by {{ $l->user->name }}</div>@endif</span></div>@endforeach</div>@endif
</x-card>
@endsection
