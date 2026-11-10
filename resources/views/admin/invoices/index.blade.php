@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Invoices')
@push('head') @include('admin.partials.filters-style') @endpush
@section('content')
<x-page-head title="Invoices" sub="Everything you've billed, and what's still owed.">
    <a class="btn primary" href="{{ route('admin.invoices.create') }}"><x-icon name="plus" :size="15" /> New invoice</a>
</x-page-head>
<div class="stats">
    <x-stat label="Outstanding" icon="receipt" :value="$outstanding->isEmpty() ? money(0) : $outstanding->map(fn ($v, $c) => money((int) $v, $c))->implode(' · ')" hint="Open invoices, unpaid balance" />
    <x-stat label="Overdue" icon="triangle-alert" :value="$overdue->isEmpty() ? money(0) : $overdue->map(fn ($v, $c) => money((int) $v, $c))->implode(' · ')" hint="Past their due date" />
</div>
<x-view-tabs :tabs="['all' => 'All', 'open' => 'Open', 'overdue' => 'Overdue', 'paid' => 'Paid', 'draft' => 'Drafts', 'closed' => 'Void']" :current="$view" :counts="$counts" label="Invoice views" />
<form class="filters" method="GET" action="{{ route('admin.invoices.index') }}">
    @if ($view !== 'all')<input type="hidden" name="view" value="{{ $view }}">@endif
    <div class="searchbox"><x-icon name="search" :size="15" /><input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search number, reference or client…" aria-label="Search invoices"></div>
    <select name="client_id" aria-label="Client"><option value="">All clients</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) ($filters['client_id'] ?? 0) === $c->id)>{{ $c->company_name ?: $c->name }}</option>@endforeach</select>
    <span class="daterange"><label class="sr-only" for="f-from">Issued from</label><input id="f-from" type="date" name="from" value="{{ $filters['from'] ?? '' }}"><span aria-hidden="true">to</span><label class="sr-only" for="f-to">Issued to</label><input id="f-to" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></span>
    <button class="btn" type="submit">Apply</button>
    @if (array_filter($filters))<a class="btn ghost" href="{{ route('admin.invoices.index', $view === 'all' ? [] : ['view' => $view]) }}">Clear filters</a>@endif
</form>
<x-card :flush="true">
    @if ($invoices->isEmpty())
        <x-empty icon="receipt" title="No invoices found" text="Nothing matches this view. Invoices appear when clients order services, renewals come due, or you create one by hand."><a class="btn primary" href="{{ route('admin.invoices.create') }}">New invoice</a></x-empty>
    @else
    <table class="t stack-sm"><thead><tr><th>Invoice</th><th>Client</th><th>Status</th><th>Issued</th><th>Due</th><th class="right">Total</th><th class="right">Balance</th></tr></thead><tbody>
        @foreach ($invoices as $i)
            <tr class="click" onclick="location.href='{{ route('admin.invoices.show', $i) }}'">
                <td data-label="Invoice"><a href="{{ route('admin.invoices.show', $i) }}"><b>{{ $i->displayNumber() }}</b></a></td>
                <td data-label="Client">{{ $i->client->displayName() }}</td>
                <td data-label="Status">@if ($i->isOverdue())<span class="pill bad" title="Past its due date">Overdue</span>@else<x-status :status="$i->status" />@endif</td>
                <td data-label="Issued" class="muted">{{ $i->issue_date?->format('j M Y') ?? '—' }}</td>
                <td data-label="Due" class="{{ $i->isOverdue() ? 'bad-text' : 'muted' }}">{{ $i->due_date?->format('j M Y') ?? '—' }}@if ($i->isOverdue())<div class="small">@php($late = (int) $i->due_date->copy()->startOfDay()->diffInDays(today())){{ $late }} {{ \Illuminate\Support\Str::plural('day', $late) }} late</div>@endif</td>
                <td data-label="Total" class="right">{{ money($i->total, $i->currency) }}</td>
                <td data-label="Balance" class="right">{{ $i->status->value === 'open' ? money($i->balance(), $i->currency) : '—' }}</td>
            </tr>
        @endforeach
    </tbody></table>
    {{ $invoices->links() }}
    @endif
</x-card>
@endsection
