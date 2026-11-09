@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Dashboard')
@section('content')
@php
    $max = max(1, collect($months)->max('value'));
    $bw = 40; $gap = 14; $h = 140;
@endphp
<x-page-head title="Dashboard" sub="How the business is doing right now." />
<div class="stats">
    <x-stat label="Revenue this month" :value="money($revenue, $currency)" icon="trending-up" />
    <x-stat label="Outstanding" :value="money($outstanding, $currency)" icon="receipt" />
    <x-stat label="Overdue invoices" :value="$overdueCount" icon="clock" />
    <x-stat label="Active services" :value="$activeServices" icon="boxes" />
    <x-stat label="New clients (30d)" :value="$newClients" icon="users" />
</div>
<x-card title="Revenue, last 12 months">
    <svg viewBox="0 0 {{ 12 * ($bw + $gap) }} {{ $h + 24 }}" width="100%" role="img" aria-label="Monthly revenue in {{ $currency }}" style="max-height:220px">
        @foreach ($months as $i => $m)
            @php($bh = $m['value'] > 0 ? max(2, (int) round($m['value'] / $max * $h)) : 2)
            <rect x="{{ $i * ($bw + $gap) + $gap / 2 }}" y="{{ $h - $bh }}" width="{{ $bw }}" height="{{ $bh }}" rx="3" fill="var(--chart-1)" opacity="{{ $m['value'] > 0 ? 1 : .25 }}"><title>{{ $m['label'] }}: {{ money($m['value'], $currency) }}</title></rect>
            <text x="{{ $i * ($bw + $gap) + $gap / 2 + $bw / 2 }}" y="{{ $h + 16 }}" text-anchor="middle" font-size="11" fill="var(--muted-foreground)">{{ $m['label'] }}</text>
        @endforeach
    </svg>
</x-card>
@if ($attention->isNotEmpty() || $failedWebhooks->isNotEmpty())
<x-card title="Needs attention">
    <div class="stack sm">
        @foreach ($attention as $s)
            <div class="notice warn"><x-icon name="triangle-alert" :size="16" /><div><a href="{{ route('admin.services.show', $s) }}"><b>{{ $s->title() }}</b></a> for {{ $s->client->displayName() }}: <span class="muted">{{ $s->provision_error }}</span></div></div>
        @endforeach
        @foreach ($failedWebhooks as $d)
            <div class="notice danger"><x-icon name="webhook" :size="16" /><div>Webhook <code>{{ $d->event }}</code> to {{ $d->endpoint?->url }} failed ({{ $d->status_code ?: 'no response' }}).</div></div>
        @endforeach
    </div>
</x-card>
@endif
<div class="grid2">
    <x-card title="Recent invoices" :flush="true">
        @if ($recentInvoices->isEmpty())<x-empty icon="receipt" title="No invoices yet" />@else
        <table class="t"><tbody>@foreach ($recentInvoices as $i)
            <tr><td>@if (Route::has('admin.invoices.show'))<a href="{{ route('admin.invoices.show', $i) }}">{{ $i->displayNumber() }}</a>@else{{ $i->displayNumber() }}@endif<div class="muted small">{{ $i->client->displayName() }}</div></td><td><x-status :status="$i->status" /></td><td class="right">{{ money($i->total, $i->currency) }}</td></tr>
        @endforeach</tbody></table>@endif
    </x-card>
    <x-card title="Recent payments" :flush="true">
        @if ($recentPayments->isEmpty())<x-empty icon="wallet" title="No payments yet" />@else
        <table class="t"><tbody>@foreach ($recentPayments as $p)
            <tr><td>{{ $p->client->displayName() }}<div class="muted small">{{ ucfirst($p->gateway) }} · {{ $p->paid_at?->format('j M Y') }}</div></td><td class="right">{{ $p->isRefund() ? '−' : '' }}{{ money($p->amount, $p->currency) }}</td></tr>
        @endforeach</tbody></table>@endif
    </x-card>
</div>
{!! extensions('admin.dashboard') !!}
@endsection
