@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Overview')
@section('content')
<x-page-head title="Hello, {{ auth()->user()->name }}" sub="Here is what's going on with your account.">
    <a class="btn primary" href="{{ route('portal.store') }}"><x-icon name="shopping-cart" :size="15" /> Order services</a>
</x-page-head>
<div class="stats">
    <x-stat label="Active services" :value="$serviceCount" icon="boxes" />
    <x-stat label="Unpaid invoices" :value="$unpaid->count()" icon="receipt" />
    <x-stat label="Balance due" :value="$owing->isEmpty() ? money(0, $client->currency) : $owing->map(fn ($v, $c) => money($v, $c))->implode(' + ')" icon="wallet" />
    <x-stat label="Quotes to review" :value="$quotes->count()" icon="file-text" />
</div>
@if ($unpaid->isNotEmpty())
<x-card title="Invoices to pay" :flush="true">
    <table class="t"><thead><tr><th>Invoice</th><th>Due</th><th class="right">Balance</th><th></th></tr></thead><tbody>
    @foreach ($unpaid as $i)
        <tr><td><a href="{{ route('portal.invoices.show', $i) }}"><b>{{ $i->number }}</b></a></td><td>{{ $i->due_date?->format('j M Y') }} @if($i->isOverdue())<span class="pill bad">Overdue</span>@endif</td><td class="right">{{ money($i->balance(), $i->currency) }}</td><td class="right"><a class="btn sm primary" href="{{ route('portal.invoices.show', $i) }}">Pay</a></td></tr>
    @endforeach</tbody></table>
</x-card>
@endif
@if ($quotes->isNotEmpty())
<x-card title="Quotes awaiting your response" :flush="true">
    <table class="t"><tbody>@foreach ($quotes as $q)
        <tr><td><a href="{{ route('portal.quotes.show', $q) }}"><b>{{ $q->number }}</b></a> <span class="muted">{{ $q->title }}</span></td><td class="right">{{ money($q->total, $q->currency) }}</td><td class="right"><a class="btn sm" href="{{ route('portal.quotes.show', $q) }}">Review</a></td></tr>
    @endforeach</tbody></table>
</x-card>
@endif
<x-card title="Your services" :flush="true">
    @if ($services->isEmpty())
        <x-empty icon="boxes" title="No services yet" text="Order something from the catalogue and it will show up here."><a class="btn primary sm" href="{{ route('portal.store') }}">Browse services</a></x-empty>
    @else
    <table class="t"><thead><tr><th>Service</th><th>Status</th><th>Next due</th><th class="right">Price</th></tr></thead><tbody>
    @foreach ($services as $s)
        <tr><td><a href="{{ route('portal.services.show', $s) }}"><b>{{ $s->title() }}</b></a></td><td><x-status :status="$s->status" /></td><td>{{ $s->next_due_date?->format('j M Y') ?? '—' }}</td><td class="right">{{ money($s->amount, $s->currency) }} <span class="muted small">{{ $s->billing_cycle->suffix() }}</span></td></tr>
    @endforeach</tbody></table>
    @endif
</x-card>
{!! extensions('portal.dashboard') !!}
@endsection
