@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Invoices')
@section('content')
<x-page-head title="Invoices" />
<div class="tabs page-tabs">@foreach (['' => 'All', 'open' => 'Unpaid', 'paid' => 'Paid', 'void' => 'Void'] as $k => $l)<a class="{{ ($status ?? '') === $k ? 'on' : '' }}" href="{{ route('portal.invoices.index', $k ? ['status' => $k] : []) }}">{{ $l }}</a>@endforeach</div>
<x-card :flush="true">
@if ($invoices->isEmpty())<x-empty icon="receipt" title="No invoices" text="Nothing here yet."/>@else
<table class="t stack-sm"><thead><tr><th>Invoice</th><th>Issued</th><th>Due</th><th>Status</th><th class="right">Total</th></tr></thead><tbody>
@foreach ($invoices as $i)
<tr><td data-label="Invoice"><a href="{{ route('portal.invoices.show', $i) }}"><b>{{ $i->number }}</b></a></td><td data-label="Issued">{{ $i->issue_date?->format('j M Y') }}</td><td data-label="Due">{{ $i->due_date?->format('j M Y') }}</td><td data-label="Status"><x-status :status="$i->status" /> @if($i->isOverdue())<span class="pill bad">Overdue</span>@endif</td><td class="right" data-label="Total">{{ money($i->total, $i->currency) }}</td></tr>
@endforeach</tbody></table>
{{ $invoices->links() }}
@endif
</x-card>
@endsection
