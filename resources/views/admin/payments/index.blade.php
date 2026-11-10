@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Payments')
@section('content')
<x-page-head title="Payments" sub="Money in and out, across every gateway." />
<div class="stats">
    @forelse ($totals as $currency => $t)
        <x-stat :label="'Received ('.$currency.')'" icon="wallet" :value="money($t['in'], $currency)" :hint="'Refunded −'.money($t['out'], $currency).' · Net '.money($t['in'] - $t['out'], $currency)" />
    @empty
        <x-stat label="Received" icon="wallet" :value="money(0)" hint="Nothing matches these filters" />
    @endforelse
</div>
<x-view-tabs :tabs="['all' => 'All', 'payments' => 'Payments', 'refunds' => 'Refunds', 'pending' => 'Pending', 'failed' => 'Failed']" :current="$view" :counts="$counts" label="Payment views" />
<form class="filters" method="GET" action="{{ route('admin.payments.index') }}">
    @if ($view !== 'all')<input type="hidden" name="view" value="{{ $view }}">@endif
    <div class="searchbox"><x-icon name="search" :size="15" /><input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search client, invoice or reference…" aria-label="Search payments"></div>
    <select name="gateway" aria-label="Gateway"><option value="">All gateways</option>@foreach ($gateways as $g)<option value="{{ $g }}" @selected(($filters['gateway'] ?? '') === $g)>{{ ucfirst($g) }}</option>@endforeach</select>
    <select name="client_id" aria-label="Client"><option value="">All clients</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) ($filters['client_id'] ?? 0) === $c->id)>{{ $c->company_name ?: $c->name }}</option>@endforeach</select>
    <span class="daterange"><label for="f-from">Received from</label><input id="f-from" type="date" name="from" value="{{ $filters['from'] ?? '' }}"><label for="f-to">to</label><input id="f-to" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></span>
    @if (! empty($filters['type']))<input type="hidden" name="type" value="{{ $filters['type'] }}">@endif
    @if (! empty($filters['status']))<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
    <button class="btn" type="submit">Apply</button>
    @if (array_filter($filters))<a class="btn ghost" href="{{ route('admin.payments.index', $view === 'all' ? [] : ['view' => $view]) }}">Clear filters</a>@endif
</form>
<x-card :flush="true">
    @if ($payments->isEmpty())
        @if (array_filter($filters) || $view !== 'all')
            <x-empty icon="wallet" title="No payments match" text="Try another tab or loosen the filters."><a class="btn" href="{{ route('admin.payments.index') }}">Show all payments</a></x-empty>
        @else
            <x-empty icon="wallet" title="No payments yet" text="Payments show up here as soon as a client pays or you record one on an invoice."><a class="btn primary" href="{{ route('admin.invoices.index', ['view' => 'open']) }}">Open invoices</a></x-empty>
        @endif
    @else
    <table class="t stack-sm"><thead><tr><th>Date</th><th>Client</th><th>Invoice</th><th>Type</th><th>Gateway</th><th>Status</th><th class="right">Amount ({{ $payments->pluck('currency')->unique()->count() === 1 ? $payments->first()->currency : 'mixed' }})</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody>
        @foreach ($payments as $p)
            @php($left = ! $p->isRefund() && $p->status->value === 'completed' ? $p->amount - $p->refundedAmount() : 0)
            <tr class="{{ $p->isRefund() ? 'is-refund' : '' }}">
                <td data-label="Date" class="muted nowrap">{{ $p->paid_at?->format('j M Y H:i') ?? $p->created_at->format('j M Y H:i') }}</td>
                <td data-label="Client">{{ $p->client->displayName() }}</td>
                <td data-label="Invoice" class="nowrap">@if ($p->invoice)<a class="link-underline" href="{{ route('admin.invoices.show', $p->invoice) }}">{{ $p->invoice->displayNumber() }}</a>@else—@endif</td>
                <td data-label="Type">@if ($p->isRefund())<span class="pill warn">Refund</span>@else Payment @endif</td>
                <td data-label="Gateway">{{ ucfirst($p->gateway) }}@if ($p->reference)<div class="muted small mono">{{ $p->reference }}</div>@endif</td>
                <td data-label="Status"><x-status :status="$p->status" /></td>
                <td data-label="Amount" class="right num {{ $p->isRefund() ? 'refund-amt' : '' }}">{{ $p->isRefund() ? '−' : '' }}{{ money($p->amount, $p->currency) }}@if ($payments->pluck('currency')->unique()->count() > 1)<div class="muted small">{{ $p->currency }}</div>@endif</td>
                <td class="right">
                    @if ($left > 0)
                        <x-menu label="Refund" icon="rotate-ccw">
                            <form method="POST" action="{{ route('admin.payments.refund', $p) }}" class="form" data-confirm="Refund this {{ money($p->amount, $p->currency) }} payment from {{ $p->client->displayName() }}? Card payments go back through the gateway." data-danger data-confirm-label="Refund payment">@csrf
                                <label for="rf-{{ $p->id }}"><span>Amount to refund (optional)</span><input id="rf-{{ $p->id }}" type="number" step="any" min="0" name="amount" placeholder="{{ \App\Support\Money::toDecimal($left, $p->currency) }}"><span class="help">Leave blank to refund the full {{ money($left, $p->currency) }} left.</span></label>
                                <button class="btn danger" type="submit">Refund payment</button></form>
                        </x-menu>
                    @endif</td>
            </tr>
        @endforeach
    </tbody></table>
    {{ $payments->links() }}
    @endif
</x-card>
@endsection
