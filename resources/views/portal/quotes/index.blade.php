@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Quotes')
@section('content')
<x-page-head title="Quotes" sub="Review proposals and accept or decline them online." />
<x-view-tabs :tabs="['' => 'All', 'open' => 'Awaiting response', 'accepted' => 'Accepted', 'closed' => 'Declined / expired']" :current="$status" :counts="$counts" param="status" label="Quote status" />
<x-card :flush="true">
@if ($quotes->isEmpty())
    @if ($status === '')<x-empty icon="file-text" title="No quotes yet" text="Quotes sent to you will appear here."/>
    @else<x-empty icon="file-text" title="No quotes in this tab" text="Nothing matches this status."><a class="btn sm" href="{{ route('portal.quotes.index') }}">Show all quotes</a></x-empty>@endif
@else
<div class="tscroll"><table class="t stack-sm"><thead><tr><th>Quote</th><th>Valid until</th><th>Status</th><th class="right">Total</th><th class="right"><span class="sr-only">Action</span></th></tr></thead><tbody>
@foreach ($quotes as $q)
<tr><td data-label="Quote"><a href="{{ route('portal.quotes.show', $q) }}"><b>{{ $q->displayNumber() }}</b></a> <span class="muted">{{ $q->title }}</span></td><td data-label="Valid until">{{ $q->valid_until?->format('j M Y') ?? '—' }}</td><td data-label="Status">@if ($q->status === \App\Enums\QuoteStatus::Sent && $q->isPastValidity())<span class="pill warn">Expired</span>@else<x-status :status="$q->status" />@endif</td><td class="right num" data-label="Total">{{ money($q->total, $q->currency) }}</td><td class="right" data-label="Action"><a class="btn sm {{ $q->isOpenForResponse() ? 'primary' : '' }}" href="{{ route('portal.quotes.show', $q) }}">{{ $q->isOpenForResponse() ? 'Review' : 'View' }}</a></td></tr>
@endforeach</tbody></table></div>
<div class="pager-wrap">{{ $quotes->links() }}</div>
@endif
</x-card>
@endsection
