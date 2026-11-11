@extends('layouts.app', ['area' => 'admin'])
@section('title', $service->title())
@section('content')
@php($st = $service->status)
@php($trialing = $service->onTrial() && $st === \App\Enums\ServiceStatus::Active)
<x-page-head :title="$service->title()" :sub="$service->product?->name" :back="route('admin.services.index')" backLabel="Services">
    @if ($st === \App\Enums\ServiceStatus::Pending)<form method="POST" action="{{ route('admin.services.activate', $service) }}">@csrf<button class="btn primary" type="submit">Activate</button></form>@endif
    @if ($st === \App\Enums\ServiceStatus::Active)
        <form method="POST" action="{{ route('admin.services.suspend', $service) }}" data-confirm="Suspend {{ $service->title() }}? The client loses access until you unsuspend it." data-confirm-label="Suspend">@csrf<button class="btn" type="submit">Suspend</button></form>
    @endif
    @if ($st === \App\Enums\ServiceStatus::Suspended)<form method="POST" action="{{ route('admin.services.unsuspend', $service) }}">@csrf<button class="btn primary" type="submit">Unsuspend</button></form>@endif
    @if ($module && count($module->adminActions()))
    <x-menu label="More actions">
        @foreach ($module->adminActions() as $key => $label)<form class="m-0" method="POST" action="{{ route('admin.services.action', $service) }}">@csrf<input type="hidden" name="action" value="{{ $key }}"><button class="item" role="menuitem" type="submit"><span>{{ $label }}</span></button></form>@endforeach
    </x-menu>
    @endif
</x-page-head>
<section class="card sumhead" aria-label="Service summary">
    <div class="sh-item"><span class="sh-k">Status</span><span class="sh-v">@if ($trialing)<span class="pill warn">Trial</span>@else<x-status :status="$st" />@endif</span><span class="sh-c">{{ $trialing ? (int) max(0, now()->startOfDay()->diffInDays($service->trial_ends_at, false)).' days left' : ($service->cancel_at_period_end ? 'Cancels '.$service->next_due_date?->format('j M Y') : ($service->activated_at ? 'Since '.$service->activated_at->format('j M Y') : 'Not activated yet')) }}</span></div>
    <div class="sh-item"><span class="sh-k">Price</span><span class="sh-v big">{{ money($service->amount, $service->currency) }}</span><span class="sh-c">{{ $service->billing_cycle->label() }}</span></div>
    <div class="sh-item"><span class="sh-k">Next due</span><span class="sh-v">{{ $service->next_due_date?->format('j M Y') ?? '—' }}</span><span class="sh-c">{{ $trialing ? 'First invoice when the trial ends' : 'Next renewal invoice' }}</span></div>
    <div class="sh-item"><span class="sh-k">Client</span><span class="sh-v"><a href="{{ route('admin.clients.show', $service->client_id) }}" class="link-underline">{{ $service->client->displayName() }}</a></span><span class="sh-c">{{ $service->product?->name ?? 'No product' }}</span></div>
</section>
@if ($service->provision_error)
    <div class="notice danger center"><x-icon name="triangle-alert" :size="16" /><div><b>Provisioning problem.</b> {{ $service->provision_error }}</div>
    @if ($st === \App\Enums\ServiceStatus::Pending)<form class="alert-action" method="POST" action="{{ route('admin.services.activate', $service) }}">@csrf<button class="btn sm" type="submit">Retry</button></form>@endif</div>
@endif
@if ($service->cancel_at_period_end)<div class="notice warn"><x-icon name="info" :size="16" /><div>Set to cancel on {{ $service->next_due_date?->format('j M Y') }}.</div></div>@endif
@if ($service->onTrial())@include('admin.services._trial')@endif
<div class="grid2">
    <x-card title="Details"><dl class="kv wide">
        <dt>Module</dt><dd>{{ $module?->label() ?? 'None' }}</dd>
        @if ($service->server)<dt>Server</dt><dd>{{ $service->server->name }}</dd>@endif
        <dt>Activated</dt><dd>{{ $service->activated_at?->format('j M Y H:i') ?? '—' }}</dd>
    </dl></x-card>
    <x-card title="Account details">
        @if ($service->provision)<dl class="kv wide">@foreach ($service->provision as $k => $v)<dt>{{ str_replace('_', ' ', ucfirst($k)) }}</dt><dd>@if (is_array($v))<dl class="kv-sub">@foreach ($v as $sk => $sv)<dt>{{ str_replace('_', ' ', $sk) }}</dt><dd>{{ is_scalar($sv) || $sv === null ? (is_bool($sv) ? ($sv ? 'yes' : 'no') : ($sv ?? '—')) : json_encode($sv) }}</dd>@endforeach</dl>@else{{ $v }}@endif</dd>@endforeach</dl>@else<span class="muted">Nothing stored by the module yet.</span>@endif
        @if ($service->secrets)
            <hr>
            @if ($reveal)
                <dl class="kv wide">@foreach ($service->secrets as $k => $v)<dt>{{ str_replace('_', ' ', ucfirst($k)) }}</dt><dd><span class="secret-box">{{ is_scalar($v) ? $v : json_encode($v) }}<button type="button" class="btn sm" data-copy="{{ is_scalar($v) ? $v : json_encode($v) }}">Copy</button></span></dd>@endforeach</dl>
            @else
                <form method="POST" action="{{ route('admin.services.reveal', $service) }}" class="row spread"><span class="muted small">{{ count($service->secrets) }} secret value(s) stored encrypted.</span>@csrf<button class="btn sm" type="submit"><x-icon name="eye" :size="14" /> Reveal</button></form>
            @endif
        @endif
    </x-card>
</div>
@include('admin.services._addons')
@include('admin.services._plan-change')
@include('admin.usage.panel', ['service' => $service])
{!! extensions('admin.service', ['service' => $service]) !!}
<form method="POST" action="{{ route('admin.services.update', $service) }}" class="card setting" data-validate>@csrf @method('PUT')
    <div class="setting-h"><h2>Edit service</h2><p>Price changes apply from the next renewal invoice. This overrides the price and cycle directly; use Change plan above for a prorated move to another plan.</p></div>
    <div class="setting-b"><div class="grid3">
        <x-field name="label" label="Label (optional)" :value="$service->label" />
        <x-field name="amount" aria-required="true" label="Recurring price ({{ $service->currency }})" type="number" step="0.01" min="0" :value="\App\Support\Money::toDecimal($service->amount, $service->currency)" />
        <x-field name="billing_cycle" label="Billing cycle" type="select" :options="$cycles" :value="$service->billing_cycle->value" />
        <x-field name="next_due_date" label="Next due date (optional)" type="date" :value="$service->next_due_date?->toDateString()" />
        @if ($servers)<x-field name="server_id" label="Server (optional)" type="select" :options="['' => 'Automatic'] + $servers" :value="$service->server_id" />@endif
    </div><x-field name="notes" label="Internal notes (optional)" type="textarea" :value="$service->notes" /></div>
    <div class="setting-f"><span></span><button class="btn primary sm" type="submit">Save</button></div>
</form>
@if ($st->isLive() || $st === \App\Enums\ServiceStatus::Pending)
<section class="card setting danger-zone">
    <div class="setting-h"><h2>Danger zone</h2><p>These end the service and remove the remote account. They can't be undone.</p></div>
    <div class="setting-b stack sm">
        <div class="row spread"><div><b>Cancel service</b><div class="help">Cancels now and removes the remote account. Past invoices stay.</div></div>
            <form method="POST" action="{{ route('admin.services.cancel', $service) }}" data-confirm="Cancel {{ $service->title() }} now? The remote account will be removed." data-confirm-label="Cancel service" data-danger>@csrf<input type="hidden" name="immediately" value="1">@if ($refundPreview)<label class="check"><input type="checkbox" name="refund" value="1" @checked(\App\Services\Cancellations::refundsByDefault())> Refund {{ money($refundPreview['amount'], $refundPreview['currency']) }} for the unused time</label>@endif<button class="btn danger sm" type="submit">Cancel service</button></form></div>
        <div class="row spread"><div><b>Terminate service</b><div class="help">Deletes the remote account and marks the service terminated.</div></div>
            <form method="POST" action="{{ route('admin.services.terminate', $service) }}" data-confirm="Terminate {{ $service->title() }}? The remote account will be deleted." data-confirm-label="Terminate" data-danger>@csrf<button class="btn danger sm" type="submit">Terminate service</button></form></div>
    </div>
</section>
@endif
<x-card title="Activity log" :flush="true">
    @if ($service->logs->isEmpty())<x-empty icon="list" title="Nothing logged yet" />@else
    <div class="card-b">@foreach ($service->logs as $l)<div class="log-line"><span class="muted">{{ $l->created_at->format('j M Y H:i') }}</span><span><b>{{ $l->action }}</b> <span class="pill {{ $l->success ? 'good' : 'bad' }}">{{ $l->success ? 'OK' : 'Failed' }}</span>@if ($l->message)<div class="muted small">{{ $l->message }}</div>@endif @if ($l->user)<div class="muted small">by {{ $l->user->name }}</div>@endif</span></div>@endforeach</div>@endif
</x-card>
@endsection
