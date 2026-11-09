@extends('layouts.app', ['area' => 'portal'])
@section('title', $service->title())
@section('content')
<x-page-head :title="$service->title()" :back="route('portal.services.index')" back-label="Services"><x-status :status="$service->status" /></x-page-head>
@if ($service->cancel_at_period_end)<div class="notice warn"><x-icon name="triangle-alert" :size="16" /><div>This service is set to cancel on {{ $service->next_due_date?->format('j M Y') }}.</div></div>@endif
@if (session('revealed'))
<x-card title="Your credentials"><p class="muted small">Shown once. Store them somewhere safe.</p>
    @foreach (session('revealed') as $k => $v)<div class="stack sm" style="margin-top:8px"><span class="muted small">{{ ucfirst(str_replace('_', ' ', $k)) }}</span><div class="secret-box"><span class="grow">{{ is_scalar($v) ? $v : json_encode($v) }}</span><button class="btn sm" type="button" data-copy="{{ is_scalar($v) ? $v : json_encode($v) }}">Copy</button></div></div>@endforeach
</x-card>
@endif
<x-card title="Details">
    <dl class="kv wide">
        <dt>Product</dt><dd>{{ $service->product?->name ?? '—' }}</dd>
        @if ($service->label)<dt>Reference</dt><dd>{{ $service->label }}</dd>@endif
        <dt>Price</dt><dd>{{ money($service->amount, $service->currency) }} {{ $service->billing_cycle->suffix() }}</dd>
        <dt>Billing cycle</dt><dd>{{ $service->billing_cycle->label() }}</dd>
        <dt>Next due</dt><dd>{{ $service->next_due_date?->format('j M Y') ?? '—' }}</dd>
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
