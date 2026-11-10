@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Overview')
@php
    $ticketsOn = \App\Services\TicketService::enabled();
    $openTickets = $ticketsOn ? \App\Models\Ticket::where('client_id', $client->id)->open()->orderByDesc('last_reply_at')->get() : collect();
@endphp
@section('content')
@php
    $overdue = $unpaid->filter->isOverdue();
    $first = $unpaid->first();
    $owingText = $owing->isEmpty() ? money(0, $client->currency) : $owing->map(fn ($v, $c) => money($v, $c))->implode(' + ');
@endphp
<x-page-head :title="'Welcome back, '.auth()->user()->name" sub="Everything on your account at a glance." />

<div class="stats">
    <a class="stat-card" href="{{ route('portal.services.index') }}"><span class="sc-k">Services</span><span class="sc-v">{{ $serviceCount }}</span></a>
    <a class="stat-card" href="{{ route('portal.invoices.index', ['status' => 'open']) }}"><span class="sc-k">Unpaid invoices</span><span class="sc-v">{{ $unpaid->count() }}</span></a>
    <a class="stat-card" href="{{ route('portal.quotes.index') }}"><span class="sc-k">Quotes to review</span><span class="sc-v">{{ $quotes->count() }}</span></a>
    @if ($ticketsOn)<a class="stat-card" href="{{ route('portal.tickets.index') }}"><span class="sc-k">Open tickets</span><span class="sc-v">{{ $openTickets->count() }}</span></a>@endif
</div>

@if ($unpaid->isNotEmpty())
<section class="card pay-hero {{ $overdue->isNotEmpty() ? 'is-overdue' : '' }}" aria-label="Payment needed">
    <div class="pay-hero-main">
        <div class="pay-hero-label">Balance due @if ($overdue->isNotEmpty())<span class="pill bad">{{ $overdue->count() }} overdue</span>@endif</div>
        <div class="pay-hero-amount num">{{ $owingText }}</div>
        <div class="muted">{{ $unpaid->count() }} unpaid {{ \Illuminate\Support\Str::plural('invoice', $unpaid->count()) }}@if ($first?->due_date) · earliest due {{ $first->due_date->format('j M Y') }}@endif</div>
    </div>
    @if ($unpaid->count() === 1)<a class="btn primary lg" href="{{ route('portal.invoices.show', $first) }}">Pay {{ money($first->balance(), $first->currency) }}</a>
    @else<a class="btn primary lg" href="{{ route('portal.invoices.index', ['status' => 'open']) }}">Review and pay</a>@endif
</section>
@endif
<div class="cols-main">
<div class="stack">
<x-card title="Your services" :flush="true">
    <x-slot:actions><a class="btn sm" href="{{ route('portal.services.index') }}">View all</a><a class="btn sm" href="{{ route('portal.store') }}">Order new</a></x-slot:actions>
    @if ($services->isEmpty())
        <x-empty icon="boxes" title="No services yet" text="Order something from the catalogue and it will show up here."><a class="btn sm" href="{{ route('portal.store') }}">Browse services</a></x-empty>
    @else
    <div class="tscroll"><table class="t stack-sm"><thead><tr><th>Service</th><th>Status</th><th>Next due</th><th class="right">Price</th></tr></thead><tbody>
    @foreach ($services as $s)
        <tr><td data-label="Service"><a href="{{ route('portal.services.show', $s) }}"><b>{{ $s->title() }}</b></a></td><td data-label="Status"><x-status :status="$s->status" />@if ($s->onTrial()) <span class="pill">Trial</span>@endif</td><td data-label="Next due">{{ $s->next_due_date?->format('j M Y') ?? '—' }}</td><td class="right num" data-label="Price">{{ money($s->amount, $s->currency) }} <span class="muted small">{{ $s->billing_cycle->suffix() }}</span></td></tr>
    @endforeach</tbody></table></div>
    @endif
</x-card>
@if ($ticketsOn)
<x-card title="Recent support tickets" :flush="true">
    <x-slot:actions><a class="btn sm" href="{{ route('portal.tickets.index') }}">View all</a><a class="btn sm" href="{{ route('portal.tickets.create') }}">Open a ticket</a></x-slot:actions>
    @if ($openTickets->isEmpty())
        <x-empty icon="life-buoy" title="No open tickets" text="Need a hand? Open a ticket and we will help."><a class="btn sm" href="{{ route('portal.tickets.create') }}">Open a ticket</a></x-empty>
    @else
    <table class="t"><tbody>@foreach ($openTickets->take(5) as $t)
        <tr><td><a href="{{ route('portal.tickets.show', $t->uuid) }}"><b>{{ $t->subject }}</b></a> <span class="muted small">{{ $t->number }}</span></td><td class="right"><x-status :status="$t->status" />@if ($t->status === \App\Enums\TicketStatus::Answered) <span class="muted small">Awaiting you</span>@endif</td></tr>
    @endforeach</tbody></table>
    @endif
</x-card>
@endif
@if ($quotes->isNotEmpty())
<x-card title="Quotes awaiting your response" :flush="true">
    <div class="tscroll"><table class="t stack-sm"><tbody>@foreach ($quotes as $q)
        <tr><td data-label="Quote"><a href="{{ route('portal.quotes.show', $q) }}"><b>{{ $q->displayNumber() }}</b></a> <span class="muted">{{ $q->title }}</span>@if ($q->valid_until)<div class="muted small">Valid until {{ $q->valid_until->format('j M Y') }}</div>@endif</td><td class="right num" data-label="Total">{{ money($q->total, $q->currency) }}</td><td class="right"><a class="btn sm" href="{{ route('portal.quotes.show', $q) }}">Review</a></td></tr>
    @endforeach</tbody></table></div>
</x-card>
@endif
</div>
<div class="stack">
<x-card title="Unpaid invoices" :flush="true">
    <x-slot:actions><a class="btn sm" href="{{ route('portal.invoices.index') }}">View all</a></x-slot:actions>
    @if ($unpaid->isEmpty())
        <x-empty icon="receipt" title="Nothing to pay" text="You have no unpaid invoices." />
    @else
    <table class="t"><tbody>
    @foreach ($unpaid as $i)
        <tr><td><a href="{{ route('portal.invoices.show', $i) }}"><b>{{ $i->displayNumber() }}</b></a><div class="muted small">Due {{ $i->due_date?->format('j M Y') ?? '—' }}</div></td><td><x-portal-invoice-status :invoice="$i" /></td><td class="right num">{{ money($i->balance(), $i->currency) }}</td></tr>
    @endforeach</tbody></table>
    @endif
</x-card>
@if ($renewals->isNotEmpty())
<x-card title="Upcoming renewals" :flush="true">
    <table class="t"><tbody>@foreach ($renewals as $s)
        <tr><td><a href="{{ route('portal.services.show', $s) }}"><b>{{ $s->title() }}</b></a><div class="muted small">{{ $s->next_due_date->format('j M Y') }} · {{ $s->next_due_date->isToday() ? 'today' : 'in '.today()->diffInDays($s->next_due_date).' days' }}</div></td><td class="right num">{{ money($s->amount, $s->currency) }}</td></tr>
    @endforeach</tbody></table>
</x-card>
@endif
<x-card title="Your details">
    <x-slot:actions><a class="btn sm" href="{{ route('portal.profile') }}">Update</a></x-slot:actions>
    <dl class="kv">
        <dt>Name</dt><dd>{{ auth()->user()->name }}</dd>
        <dt>Email</dt><dd>{{ auth()->user()->email }}</dd>
        @if ($client->company_name)<dt>Company</dt><dd>{{ $client->company_name }}</dd>@endif
        @if ($client->phone)<dt>Phone</dt><dd>{{ $client->phone }}</dd>@endif
    </dl>
</x-card>
</div>
</div>
{!! extensions('portal.dashboard') !!}
@endsection
