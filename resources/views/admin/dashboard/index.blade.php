@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Dashboard')
@section('content')
@php
    $max = max(1, collect($months)->max('value'));
    // Round the axis up to a tidy top value (1, 2, 2.5, 5 x 10^n) so gridlines land on readable numbers.
    $unit = 10 ** \App\Support\Money::digits($currency);
    $maxMajor = max(1, $max / $unit);
    $mag = 10 ** (int) floor(log10($maxMajor));
    $topMajor = $mag * 10;
    foreach ([1, 2, 2.5, 5, 10] as $step) { if ($step * $mag >= $maxMajor) { $topMajor = $step * $mag; break; } }
    $top = (int) round($topMajor * $unit);
    $bw = 34; $gap = 16; $h = 150; $left = 64; $w = $left + 12 * ($bw + $gap);
    $delta = $revenuePrev > 0 ? (int) round(($revenue - $revenuePrev) / $revenuePrev * 100) : null;
    $monthFrom = now()->startOfMonth()->toDateString();
@endphp
<x-page-head title="Dashboard" sub="How the business is doing right now. Figures are in {{ $currency }}, internal accounts excluded." />

<div class="stats mt-12">
    <a class="stat-card big" href="{{ route('admin.invoices.index', ['view' => 'overdue']) }}">
        <span class="sc-k">Overdue</span>
        <span class="sc-v {{ $overdueCount ? 'bad-text' : '' }}">{{ money($overdueAmount, $currency) }}</span>
        <span class="sc-c muted">{{ $overdueCount }} {{ \Illuminate\Support\Str::plural('invoice', $overdueCount) }} past due</span>
    </a>
    <a class="stat-card big" href="{{ route('admin.invoices.index', ['view' => 'open']) }}">
        <span class="sc-k">Outstanding</span>
        <span class="sc-v">{{ money($outstanding, $currency) }}</span>
        <span class="sc-c muted">{{ $outstandingCount }} open {{ \Illuminate\Support\Str::plural('invoice', $outstandingCount) }}</span>
    </a>
    <a class="stat-card big" href="{{ route('admin.payments.index', ['from' => $monthFrom]) }}">
        <span class="sc-k">Revenue this month</span>
        <span class="sc-vd"><span class="sc-v">{{ money($revenue, $currency) }}</span>@if ($delta !== null)<span class="delta compact {{ $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat') }}">{{ $delta > 0 ? '+' : ($delta < 0 ? '−' : '') }}{{ abs($delta) }}%</span>@endif</span>
        <span class="sc-c muted">@if ($delta === null)Nothing received last month @else vs {{ money($revenuePrev, $currency) }} over the same days last month @endif</span>
    </a>
</div>
<div class="stats mt-12 mb-16">
    @if (Route::has('admin.services.index'))
    <a class="stat-card" href="{{ route('admin.services.index', ['status' => 'active']) }}"><span class="sc-k">Active services</span><span class="sc-v">{{ number_format($activeServices) }}</span></a>
    @endif
    @if (Route::has('admin.clients.index'))
    <a class="stat-card" href="{{ route('admin.clients.index') }}"><span class="sc-k">New clients, last 30 days</span><span class="sc-v">{{ number_format($newClients) }}</span></a>
    @endif
    @if ($ticketsAwaiting !== null)
    <a class="stat-card" href="{{ route('admin.tickets.index', ['tab' => 'awaiting']) }}"><span class="sc-k">Tickets awaiting reply</span><span class="sc-v {{ $ticketsAwaiting ? 'warn-text' : '' }}">{{ number_format($ticketsAwaiting) }}</span></a>
    @endif
</div>

<x-card title="Revenue by month, last 12 months">
    <p class="muted small mb-8">Net payments received in {{ $currency }} (refunds deducted). Hover a bar for the exact amount.</p>
    <svg viewBox="0 -8 {{ $w }} {{ $h + 38 }}" width="100%" role="img" aria-label="Bar chart of monthly revenue in {{ $currency }}. Latest month: {{ money(collect($months)->last()['value'], $currency) }}.">
        @foreach ([0, .5, 1] as $g)
            @php($gy = $h - (int) round($g * $h))
            <line x1="{{ $left }}" x2="{{ $w }}" y1="{{ $gy }}" y2="{{ $gy }}" stroke="var(--border)" stroke-width="1" @if ($g > 0) stroke-dasharray="3 3" @endif/>
            <text x="{{ $left - 8 }}" y="{{ $gy + 4 }}" text-anchor="end" font-size="11" fill="var(--muted-foreground)">{{ money((int) round($g * $top), $currency) }}</text>
        @endforeach
        @foreach ($months as $i => $m)
            @php($bh = $m['value'] > 0 ? max(2, (int) round($m['value'] / $top * $h)) : 2)
            @php($bx = $left + $i * ($bw + $gap) + $gap / 2)
            <rect x="{{ $bx }}" y="{{ $h - $bh }}" width="{{ $bw }}" height="{{ $bh }}" rx="3" fill="var(--chart-1)" opacity="{{ $m['value'] > 0 ? 1 : .3 }}"><title>{{ $m['label'] }}: {{ money($m['value'], $currency) }}</title></rect>
            <text x="{{ $bx + $bw / 2 }}" y="{{ $h + 18 }}" text-anchor="middle" font-size="11" fill="var(--muted-foreground)">{{ $m['label'] }}</text>
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
        <x-slot:actions><a class="small" href="{{ route('admin.invoices.index') }}">View all</a></x-slot:actions>
        @if ($recentInvoices->isEmpty())<x-empty icon="receipt" title="No invoices yet" />@else
        <table class="t"><tbody>@foreach ($recentInvoices as $i)
            <tr><td>@if (Route::has('admin.invoices.show'))<a href="{{ route('admin.invoices.show', $i) }}">{{ $i->displayNumber() }}</a>@else{{ $i->displayNumber() }}@endif<div class="muted small">{{ $i->client->displayName() }}</div></td><td>@if ($i->isOverdue())<span class="pill bad">Overdue</span>@else<x-status :status="$i->status" />@endif</td><td class="right num">{{ money($i->total, $i->currency) }}</td></tr>
        @endforeach</tbody></table>@endif
    </x-card>
    <x-card title="Recent payments" :flush="true">
        <x-slot:actions><a class="small" href="{{ route('admin.payments.index') }}">View all</a></x-slot:actions>
        @if ($recentPayments->isEmpty())<x-empty icon="wallet" title="No payments yet" />@else
        <table class="t"><tbody>@foreach ($recentPayments as $p)
            <tr><td>@if ($p->invoice)<a href="{{ route('admin.invoices.show', $p->invoice) }}">{{ $p->client->displayName() }}</a>@else{{ $p->client->displayName() }}@endif<div class="muted small">{{ ucfirst($p->gateway) }} · {{ $p->paid_at?->format('j M Y') }}</div></td><td class="right num {{ $p->isRefund() ? 'refund-amt' : '' }}">{{ $p->isRefund() ? '−' : '' }}{{ money($p->amount, $p->currency) }}@if ($p->isRefund())<div class="small">Refund</div>@endif</td></tr>
        @endforeach</tbody></table>@endif
    </x-card>
</div>
{!! extensions('admin.dashboard') !!}
@endsection
