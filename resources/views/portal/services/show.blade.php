@extends('layouts.app', ['area' => 'portal'])
@section('title', $service->title())
@section('content')
@php
    $isActive = $service->status === \App\Enums\ServiceStatus::Active;
    $primaryKey = array_key_exists('login', $actions) ? 'login' : array_key_first($actions);
    $secondary = collect($actions)->except($primaryKey ?? '')->all();
    $address = null;
    $facts = [];
    foreach (($details ?? collect(($service->provision ?? []))->filter(fn ($v) => is_scalar($v))->mapWithKeys(fn ($v, $k) => [ucfirst(str_replace('_', ' ', $k)) => $v])->all()) as $label => $value) {
        if (blank($value)) { continue; }
        if ($address === null && str_starts_with((string) $value, 'https://')) { $address = ['label' => $label, 'value' => (string) $value]; continue; }
        $facts[$label] = $value;
    }
    $recurring = $service->billing_cycle->isRecurring();
    $liveAddons = $addons->whereIn('status', [\App\Enums\ServiceStatus::Active, \App\Enums\ServiceStatus::Pending, \App\Enums\ServiceStatus::Suspended]);
    $total = $service->amount + $liveAddons->sum('amount');
@endphp
<div class="svc-hero">
    <div>
        <div class="crumb"><a href="{{ route('portal.services.index') }}"><x-icon name="chevron-left" :size="14" /> Services</a></div>
        <h1>{{ $service->title() }}</h1>
        <div class="svc-sub"><x-status :status="$service->status" />@if ($service->onTrial()) <span class="pill">Trial</span>@endif
            <span>{{ $service->product?->name }}@if ($recurring) · {{ money($service->amount, $service->currency) }} {{ $service->billing_cycle->suffix() }}@endif
            @if ($service->cancel_at_period_end && $service->next_due_date) · ends {{ $service->next_due_date->format('j M Y') }}@elseif ($recurring && $service->next_due_date && $isActive) · renews {{ $service->next_due_date->format('j M Y') }}@endif</span></div>
    </div>
    <div class="svc-hero-actions">
        @if (\App\Services\TicketService::enabled())<a class="btn" href="{{ route('portal.tickets.create', ['service' => $service->uuid]) }}"><x-icon name="life-buoy" :size="15" /> Get help</a>@endif
        @if ($primaryKey)<form method="POST" action="{{ route('portal.services.action', [$service, $primaryKey]) }}">@csrf<button class="btn primary lg" type="submit">{{ $actions[$primaryKey] }} <x-icon name="external-link" :size="15" /></button></form>@endif
    </div>
</div>
<x-order-placed />
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
<div class="svc-cols">
<div class="svc-main">
@if ($address || $facts || $secondary || $hasSecrets)
<section class="card svc-card">
    <h2>{{ $service->isAddon() ? 'About this add-on' : 'Your service' }}</h2>
    @if ($address)
    <div class="svc-addr"><span class="svc-k">{{ $address['label'] }}</span><a class="link-underline" href="{{ $address['value'] }}" rel="noopener">{{ $address['value'] }}</a><button class="btn sm" type="button" data-copy="{{ $address['value'] }}">Copy</button></div>
    @endif
    @if ($facts)
    <dl class="svc-facts">@foreach ($facts as $label => $value)<dt>{{ $label }}</dt><dd>{{ $value }}</dd>@endforeach</dl>
    @endif
    @if ($secondary || $hasSecrets)
    <div class="svc-acts">
        @foreach ($secondary as $key => $label)<form method="POST" action="{{ route('portal.services.action', [$service, $key]) }}">@csrf<button class="btn sm" type="submit">{{ $label }}</button></form>@endforeach
        @if ($hasSecrets)<form method="POST" action="{{ route('portal.services.reveal', $service) }}">@csrf<button class="btn sm" type="submit"><x-icon name="eye" :size="14" /> Reveal credentials</button></form>@endif
    </div>
    @endif
</section>
@endif
@include('portal.services._addons')
@if ($preview && $canChangePlan)
<section class="card svc-card"><h2>Change plan</h2>
@include('portal.services._plan')
</section>
@endif
@include('portal.usage.panel', ['service' => $service])
{!! extensions('portal.service', ['service' => $service]) !!}
<details class="svc-cancel">
<summary>{{ $service->isAddon() ? 'Remove this add-on…' : 'Cancel this service…' }}</summary>
@if (in_array($service->status->value, ['active', 'suspended', 'pending']) && ! $service->cancel_at_period_end)
@php
    $cancelMode = \App\Services\Cancellations::mode();
    $endsNow = $service->status->value === 'pending' || ! $service->next_due_date || $service->onTrial();
    $cancelNow = $endsNow || $cancelMode === 'immediate';
    $cancelChoice = ! $endsNow && $cancelMode === 'customer_choice';
    $termEnd = $service->next_due_date?->format('j M Y');
    $refundNote = $refundPreview ? 'You will be refunded '.money($refundPreview['amount'], $refundPreview['currency']).' for the '.$refundPreview['remaining_days'].' unused '.\Illuminate\Support\Str::plural('day', $refundPreview['remaining_days']).', to your original payment method.' : null;
@endphp
<form class="card setting danger-zone" method="POST" action="{{ route('portal.services.cancel', $service) }}" data-confirm="{{ $cancelChoice ? 'Cancel this service?' : ($cancelNow ? 'Cancel this service now? It stops working straight away.' : 'Cancel this service? It stays active until '.$termEnd.'.') }}" data-danger data-confirm-label="{{ $service->isAddon() ? 'Remove add-on' : 'Cancel service' }}">@csrf
    <div class="setting-h"><h2>{{ $service->isAddon() ? 'Remove add-on' : 'Cancel service' }}</h2><p>
        @if ($cancelChoice)Choose when it ends.
        @elseif ($cancelNow)Your service ends straight away.@if ($cancelMode === 'immediate' && $refundNote) {{ $refundNote }}@endif
        @else Your {{ $service->isAddon() ? 'add-on' : 'service' }} stays active until the end of the paid period{{ $termEnd ? ' ('.$termEnd.')' : '' }}.
        @endif
    </p></div>
    <div class="setting-b stack">
        @if ($cancelChoice)
        <fieldset class="choice-group"><legend>When should it end?</legend>
            <label class="choice"><input type="radio" name="when" value="end" checked><span><b>At the end of the billing term</b><span class="help">Stays active until {{ $termEnd }}, then stops. No further invoices.</span></span></label>
            <label class="choice"><input type="radio" name="when" value="now"><span><b>Immediately</b><span class="help">Stops working now.@if ($refundNote) {{ $refundNote }}@endif</span></span></label>
        </fieldset>
        @endif
        <x-field name="reason" label="Reason (optional)" />
    </div>
    <div class="setting-f"><span></span><button class="btn destructive sm" type="submit">{{ $service->isAddon() ? 'Remove add-on' : ($cancelNow || $cancelChoice ? 'Cancel service' : 'Request cancellation') }}</button></div>
</form>
@endif
</details>
</div>
<aside class="svc-aside">
<section class="card svc-card svc-bill">
    <h2>Billing</h2>
    @if ($recurring)
    <div class="svc-total">{{ money($total, $service->currency) }} <span>{{ $service->billing_cycle->suffix() }}</span></div>
    @if ($liveAddons->isNotEmpty() && ! $service->isAddon())<p class="svc-break">{{ $service->product?->name }} {{ money($service->amount, $service->currency) }}@foreach ($liveAddons as $a) + {{ $a->product?->name }} {{ money($a->amount, $a->currency) }}@endforeach</p>@endif
    @else
    <div class="svc-total">{{ money($service->amount, $service->currency) }} <span>one-off</span></div>
    @endif
    <dl class="svc-kv">
        @if ($service->next_due_date)<dt>{{ $service->cancel_at_period_end ? 'Ends' : 'Next payment' }}</dt><dd>{{ $service->next_due_date->format('j M Y') }}</dd>@endif
        <dt>Cycle</dt><dd>{{ $service->billing_cycle->label() }}</dd>
        @if ($paymentMethod)<dt>Pays with</dt><dd>{{ $paymentMethod->displayName() }}</dd>@endif
        @if ($service->activated_at)<dt>Active since</dt><dd>{{ $service->activated_at->format('j M Y') }}</dd>@endif
    </dl>
    <div class="svc-acts"><a class="btn sm" href="{{ route('portal.invoices.index') }}">Invoices</a><a class="btn sm" href="{{ route('portal.payment-methods.index') }}">Payment methods</a></div>
</section>
@if ($canChangePlan && ! $preview && $planOptions->isNotEmpty())
<details class="card svc-card svc-plan">
    <summary><span><h2>Plan</h2><span class="svc-break">You are on <b>{{ $service->product?->name }}</b>.</span></span><span class="btn sm">Change plan</span></summary>
@include('portal.services._plan')
</details>
@endif
</aside>
</div>
@endsection
