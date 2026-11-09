@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Quotes')
@section('content')
<x-page-head title="Quotes" sub="Proposals clients can accept online.">
    <a class="btn primary" href="{{ route('admin.quotes.create') }}"><x-icon name="plus" :size="15" /> New quote</a>
</x-page-head>
<form class="filters" method="GET" action="{{ route('admin.quotes.index') }}">
    <div class="searchbox"><x-icon name="search" :size="15" /><input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Number, title, client…"></div>
    <select name="status" aria-label="Status"><option value="">All statuses</option>@foreach (\App\Enums\QuoteStatus::cases() as $s)<option value="{{ $s->value }}" @selected(($filters['status'] ?? '') === $s->value)>{{ $s->label() }}</option>@endforeach</select>
    <select name="client_id" aria-label="Client"><option value="">All clients</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) ($filters['client_id'] ?? 0) === $c->id)>{{ $c->company_name ?: $c->name }}</option>@endforeach</select>
    <button class="btn" type="submit">Filter</button>
    @if (array_filter($filters))<a class="btn ghost" href="{{ route('admin.quotes.index') }}">Clear</a>@endif
</form>
<x-card :flush="true">
    @if ($quotes->isEmpty())
        <x-empty icon="file-text" title="No quotes yet" text="Send a client a quote; when they accept, it turns into an invoice and orders their services."><a class="btn primary" href="{{ route('admin.quotes.create') }}">New quote</a></x-empty>
    @else
    <table class="t stack-sm"><thead><tr><th>Quote</th><th>Client</th><th>Status</th><th>Valid until</th><th class="right">Total</th></tr></thead><tbody>
        @foreach ($quotes as $q)
            <tr class="click" onclick="location.href='{{ route('admin.quotes.show', $q) }}'">
                <td data-label="Quote"><a href="{{ route('admin.quotes.show', $q) }}"><b>{{ $q->displayNumber() }}</b></a>@if ($q->title)<div class="muted small">{{ $q->title }}</div>@endif</td>
                <td data-label="Client">{{ $q->client->displayName() }}</td>
                <td data-label="Status"><x-status :status="$q->status" /></td>
                <td data-label="Valid until" class="muted">{{ $q->valid_until?->format('j M Y') ?? '—' }}</td>
                <td data-label="Total" class="right">{{ money($q->total, $q->currency) }}</td>
            </tr>
        @endforeach
    </tbody></table>
    {{ $quotes->links() }}
    @endif
</x-card>
@endsection
