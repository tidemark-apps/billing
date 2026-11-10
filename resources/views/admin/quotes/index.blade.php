@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Quotes')
@push('head') @include('admin.partials.filters-style') @endpush
@section('content')
<x-page-head title="Quotes" sub="Proposals clients can accept online.">
    <a class="btn primary" href="{{ route('admin.quotes.create') }}"><x-icon name="plus" :size="15" /> New quote</a>
</x-page-head>
<div class="stats">
    <x-stat label="Awaiting a response" icon="send" :value="$awaiting->isEmpty() ? money(0) : $awaiting->map(fn ($v, $c) => money((int) $v, $c))->implode(' · ')" :hint="$counts['sent'].' sent '.\Illuminate\Support\Str::plural('quote', $counts['sent'])" />
</div>
<x-view-tabs :tabs="['all' => 'All', 'draft' => 'Drafts', 'sent' => 'Sent', 'accepted' => 'Accepted', 'converted' => 'Converted', 'declined' => 'Declined', 'expired' => 'Expired']" :current="$view" :counts="$counts" label="Quote views" />
<form class="filters" method="GET" action="{{ route('admin.quotes.index') }}">
    @if ($view !== 'all')<input type="hidden" name="view" value="{{ $view }}">@endif
    <div class="searchbox"><x-icon name="search" :size="15" /><input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search number, title or client…" aria-label="Search quotes"></div>
    <select name="client_id" aria-label="Client"><option value="">All clients</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) ($filters['client_id'] ?? 0) === $c->id)>{{ $c->company_name ?: $c->name }}</option>@endforeach</select>
    @if (! empty($filters['status']))<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
    <button class="btn" type="submit">Apply</button>
    @if (array_filter($filters))<a class="btn ghost" href="{{ route('admin.quotes.index', $view === 'all' ? [] : ['view' => $view]) }}">Clear filters</a>@endif
</form>
<x-card :flush="true">
    @if ($quotes->isEmpty())
        @if (array_filter($filters) || $view !== 'all')
            <x-empty icon="file-text" title="No quotes match" text="Try another tab or loosen the filters."><a class="btn" href="{{ route('admin.quotes.index') }}">Show all quotes</a></x-empty>
        @else
            <x-empty icon="file-text" title="No quotes yet" text="Send a client a quote; when they accept, it turns into an invoice and orders their services."><a class="btn primary" href="{{ route('admin.quotes.create') }}">New quote</a></x-empty>
        @endif
    @else
    <table class="t stack-sm"><thead><tr><th>Quote</th><th>Client</th><th>Status</th><th>Valid until</th><th class="right">Total</th></tr></thead><tbody>
        @foreach ($quotes as $q)
            <tr class="click" onclick="location.href='{{ route('admin.quotes.show', $q) }}'">
                <td data-label="Quote"><a href="{{ route('admin.quotes.show', $q) }}"><b>{{ $q->displayNumber() }}</b></a>@if ($q->title)<div class="muted small">{{ $q->title }}</div>@endif</td>
                <td data-label="Client">{{ $q->client->displayName() }}</td>
                <td data-label="Status"><x-status :status="$q->status" /></td>
                <td data-label="Valid until" class="{{ $q->status->value === 'sent' && $q->isPastValidity() ? 'bad-text' : 'muted' }}">{{ $q->valid_until?->format('j M Y') ?? '—' }}@if ($q->status->value === 'sent' && $q->isPastValidity())<div class="small">Past its date</div>@endif</td>
                <td data-label="Total" class="right">{{ money($q->total, $q->currency) }}</td>
            </tr>
        @endforeach
    </tbody></table>
    {{ $quotes->links() }}
    @endif
</x-card>
@endsection
