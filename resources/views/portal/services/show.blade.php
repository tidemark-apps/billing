@extends('layouts.app', ['area' => 'portal'])
@section('title', $service->title())
@section('content')
<x-page-head :title="$service->title()" :back="route('portal.services.index')" back-label="Services">
    @if (\App\Services\TicketService::enabled())<a class="btn" href="{{ route('portal.tickets.create', ['service' => $service->uuid]) }}"><x-icon name="life-buoy" :size="15" /> Get help</a>@endif</x-page-head>
<x-order-placed />
<section class="card sumhead" aria-label="Service summary">
    <div class="sh-item"><span class="sh-k">Status</span><span class="sh-v"><x-status :status="$service->status" />@if ($service->onTrial()) <span class="pill">Trial</span>@endif</span><span class="sh-c">{{ $service->cancel_at_period_end ? 'Cancels at end of period' : ($service->activated_at ? 'Active since '.$service->activated_at->format('j M Y') : '') }}</span></div>
    <div class="sh-item"><span class="sh-k">Next due</span><span class="sh-v">{{ $service->next_due_date?->format('j M Y') ?? '—' }}</span><span class="sh-c">{{ $service->next_due_date ? $service->next_due_date->diffForHumans() : '' }}</span></div>
    <div class="sh-item"><span class="sh-k">Price</span><span class="sh-v big">{{ money($service->amount, $service->currency) }}</span><span class="sh-c">{{ $service->billing_cycle->suffix() }}</span></div>
    <div class="sh-item"><span class="sh-k">Billing cycle</span><span class="sh-v">{{ $service->billing_cycle->label() }}</span><span class="sh-c">{{ $service->currency }}</span></div>
</section>
@if ($service->cancel_at_period_end)<div class="notice warn"><x-icon name="triangle-alert" :size="16" /><div>This service is set to cancel on {{ $service->next_due_date?->format('j M Y') }}.</div></div>@endif
@if ($service->onTrial())
<div class="notice"><x-icon name="info" :size="16" /><div><b>Free trial until {{ $service->trial_ends_at->format('j M Y') }}.</b> After that it is {{ money($service->amount, $service->currency) }} {{ $service->billing_cycle->suffix() }}. You can cancel any time before then and you will not be charged.</div></div>
@endif
@if ($scheduledChange)
<div class="notice warn center"><x-icon name="info" :size="16" /><div>Your plan changes to <b>{{ $scheduledChange->toProduct?->name }}</b> ({{ $scheduledChange->to_cycle->label() }}, {{ money($scheduledChange->to_amount, $scheduledChange->currency) }} {{ $scheduledChange->to_cycle->suffix() }}) on {{ $scheduledChange->effective_date->format('j M Y') }}.</div>
    <form class="alert-action" method="POST" action="{{ route('portal.services.plan-change.cancel', $service) }}">@csrf @method('DELETE')<button class="btn sm" type="submit">Cancel scheduled change</button></form></div>
@endif
@if (session('revealed'))
<x-card title="Your credentials"><p class="muted small">Shown once. Store them somewhere safe.</p>
    @foreach (session('revealed') as $k => $v)<div class="stack sm mt-8"><span class="muted small">{{ ucfirst(str_replace('_', ' ', $k)) }}</span><div class="secret-box"><span class="grow">{{ is_scalar($v) ? $v : json_encode($v) }}</span><button class="btn sm" type="button" data-copy="{{ is_scalar($v) ? $v : json_encode($v) }}">Copy</button></div></div>@endforeach
</x-card>
@endif
<x-card title="Details">
    <dl class="kv wide">
        <dt>Product</dt><dd>{{ $service->product?->name ?? '—' }}</dd>
        @if ($service->label)<dt>Reference</dt><dd>{{ $service->label }}</dd>@endif
        @if ($service->activated_at)<dt>Active since</dt><dd>{{ $service->activated_at->format('j M Y') }}</dd>@endif
        @foreach (($service->provision ?? []) as $k => $v)@if (is_scalar($v))<dt>{{ ucfirst(str_replace('_', ' ', $k)) }}</dt><dd>{{ $v }}</dd>@endif @endforeach
    </dl>
</x-card>
@if ($actions || $hasSecrets)
<x-card title="Manage">
    <div class="row wrap">
        @foreach ($actions as $key => $label)<form method="POST" action="{{ route('portal.services.action', [$service, $key]) }}">@csrf<button class="btn" type="submit">{{ $label }}</button></form>@endforeach
        @if ($hasSecrets)<form method="POST" action="{{ route('portal.services.reveal', $service) }}">@csrf<button class="btn" type="submit"><x-icon name="eye" :size="15" /> Reveal credentials</button></form>@endif
    </div>
</x-card>
@endif
@if ($canChangePlan && ($preview || $planOptions->isNotEmpty()))
<x-card title="Change plan">
    @if ($preview)
        <p class="muted small mb-16">Here is what changes. Nothing happens until you confirm.</p>
        @include('portal.services._plan-preview', ['preview' => $preview, 'staff' => false])
        <form method="POST" action="{{ route('portal.services.plan-change.store', $service) }}" class="row mt-16">@csrf
            <input type="hidden" name="plan" value="{{ $selected['plan'] }}">
            <button class="btn primary" type="submit">Confirm change</button><a class="btn" href="{{ route('portal.services.show', $service) }}">Back</a>
        </form>
    @else
        <form method="POST" action="{{ route('portal.services.plan-change.preview', $service) }}" class="stack">@csrf
            <label><span>Switch to</span>
                <select name="plan" required>
                    @foreach ($planOptions->groupBy(fn ($o) => $o['product']->name) as $name => $opts)
                        <optgroup label="{{ $name }}">@foreach ($opts as $o)<option value="{{ $o['product']->id }}:{{ $o['cycle']->value }}">{{ $o['cycle']->label() }} · {{ money($o['amount'], $service->currency) }} {{ $o['cycle']->suffix() }}</option>@endforeach</optgroup>
                    @endforeach
                </select>
            </label>
            <p class="help">Upgrades start today and you pay only the difference for the rest of the period. Downgrades apply at your next renewal.</p>
            <div><button class="btn" type="submit">Preview change</button></div>
        </form>
    @endif
</x-card>
@endif
@include('portal.usage.panel', ['service' => $service])
{!! extensions('portal.service', ['service' => $service]) !!}
@if (in_array($service->status->value, ['active', 'suspended', 'pending']) && ! $service->cancel_at_period_end)
<form class="card setting danger-zone" method="POST" action="{{ route('portal.services.cancel', $service) }}" data-confirm="Cancel this service? Cancellation takes effect at the end of the billing period." data-danger data-confirm-label="Cancel service">@csrf
    <div class="setting-h"><h2>Cancel service</h2><p>Your service stays active until the end of the paid period, unless it hasn't started yet.</p></div>
    <div class="setting-b"><x-field name="reason" label="Reason (optional)" /></div>
    <div class="setting-f"><span></span><button class="btn destructive sm" type="submit">Request cancellation</button></div>
</form>
@endif
@endsection
