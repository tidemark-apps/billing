@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Payments')
@section('content')
<x-page-head title="Payments" sub="Money in and out, across every gateway." />
<div class="stats">
    @forelse ($totals as $currency => $t)
        <x-stat :label="'Received · '.$currency" icon="wallet" :value="money($t['in'], $currency)" :hint="'Refunded '.money($t['out'], $currency).' · net '.money($t['in'] - $t['out'], $currency)" />
    @empty
        <x-stat label="Received" icon="wallet" :value="money(0)" hint="Nothing matches these filters" />
    @endforelse
</div>
<form class="filters" method="GET" action="{{ route('admin.payments.index') }}">
    <select name="gateway" aria-label="Gateway"><option value="">All gateways</option>@foreach ($gateways as $g)<option value="{{ $g }}" @selected(($filters['gateway'] ?? '') === $g)>{{ ucfirst($g) }}</option>@endforeach</select>
    <select name="type" aria-label="Type"><option value="">Payments and refunds</option><option value="payment" @selected(($filters['type'] ?? '') === 'payment')>Payments</option><option value="refund" @selected(($filters['type'] ?? '') === 'refund')>Refunds</option></select>
    <select name="status" aria-label="Status"><option value="">All statuses</option>@foreach (\App\Enums\PaymentStatus::cases() as $s)<option value="{{ $s->value }}" @selected(($filters['status'] ?? '') === $s->value)>{{ $s->label() }}</option>@endforeach</select>
    <select name="client_id" aria-label="Client"><option value="">All clients</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) ($filters['client_id'] ?? 0) === $c->id)>{{ $c->company_name ?: $c->name }}</option>@endforeach</select>
    <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" aria-label="From"><input type="date" name="to" value="{{ $filters['to'] ?? '' }}" aria-label="To">
    <button class="btn" type="submit">Filter</button>
    @if (array_filter($filters))<a class="btn ghost" href="{{ route('admin.payments.index') }}">Clear</a>@endif
</form>
<x-card :flush="true">
    @if ($payments->isEmpty())
        <x-empty icon="wallet" title="No payments found" text="Payments show up here as soon as a client pays or you record one on an invoice." />
    @else
    <table class="t stack-sm"><thead><tr><th>Date</th><th>Client</th><th>Invoice</th><th>Gateway</th><th>Status</th><th class="right">Amount</th></tr></thead><tbody>
        @foreach ($payments as $p)
            @php($left = ! $p->isRefund() && $p->status->value === 'completed' ? $p->amount - $p->refundedAmount() : 0)
            <tr>
                <td data-label="Date" class="muted">{{ $p->paid_at?->format('j M Y H:i') ?? $p->created_at->format('j M Y H:i') }}</td>
                <td data-label="Client">{{ $p->client->displayName() }}</td>
                <td data-label="Invoice">@if ($p->invoice)<a href="{{ route('admin.invoices.show', $p->invoice) }}" style="text-decoration:underline">{{ $p->invoice->displayNumber() }}</a>@else—@endif</td>
                <td data-label="Gateway">{{ ucfirst($p->gateway) }}@if ($p->reference)<div class="muted small mono">{{ $p->reference }}</div>@endif</td>
                <td data-label="Status">@if ($p->isRefund())<span class="pill warn">Refund</span> @endif<x-status :status="$p->status" /></td>
                <td data-label="Amount" class="right">{{ $p->isRefund() ? '−' : '' }}{{ money($p->amount, $p->currency) }}
                    @if ($left > 0)
                        <details style="text-align:right"><summary class="small muted" style="cursor:pointer">Refund…</summary>
                            <form method="POST" action="{{ route('admin.payments.refund', $p) }}" class="row" style="justify-content:flex-end;margin-top:6px" data-confirm="Refund this payment? Card payments are returned through the gateway." data-danger data-confirm-label="Refund">@csrf
                                <input type="number" step="any" min="0" name="amount" placeholder="{{ \App\Support\Money::toDecimal($left, $p->currency) }}" style="width:110px" aria-label="Refund amount"><button class="btn danger sm" type="submit">Refund</button></form></details>
                    @endif</td>
            </tr>
        @endforeach
    </tbody></table>
    {{ $payments->links() }}
    @endif
</x-card>
@endsection
