@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Quotes')
@section('content')
<x-page-head title="Quotes" />
<x-card :flush="true">
@if ($quotes->isEmpty())<x-empty icon="file-text" title="No quotes" text="Quotes sent to you will appear here."/>@else
<table class="t stack-sm"><thead><tr><th>Quote</th><th>Valid until</th><th>Status</th><th class="right">Total</th></tr></thead><tbody>
@foreach ($quotes as $q)
<tr><td data-label="Quote"><a href="{{ route('portal.quotes.show', $q) }}"><b>{{ $q->number }}</b></a> <span class="muted">{{ $q->title }}</span></td><td data-label="Valid until">{{ $q->valid_until?->format('j M Y') }}</td><td data-label="Status"><x-status :status="$q->status" /></td><td class="right" data-label="Total">{{ money($q->total, $q->currency) }}</td></tr>
@endforeach</tbody></table>
{{ $quotes->links() }}
@endif
</x-card>
@endsection
