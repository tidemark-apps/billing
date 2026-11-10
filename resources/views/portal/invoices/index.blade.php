@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Invoices')
@section('content')
<x-page-head title="Invoices" sub="Pay what is due and download receipts for what you have paid." />
<x-view-tabs :tabs="['' => 'All', 'open' => 'Unpaid', 'paid' => 'Paid', 'void' => 'Void']" :current="$status" :counts="$counts" param="status" label="Invoice status" />
<x-card :flush="true">
@if ($invoices->isEmpty())
    @if ($status === '')<x-empty icon="receipt" title="No invoices yet" text="When we bill you, invoices will show up here."><a class="btn sm" href="{{ route('portal.store') }}">Browse services</a></x-empty>
    @else<x-empty icon="receipt" title="No {{ ['open' => 'unpaid', 'paid' => 'paid', 'void' => 'void'][$status] ?? '' }} invoices" text="Nothing matches this tab."><a class="btn sm" href="{{ route('portal.invoices.index') }}">Show all invoices</a></x-empty>@endif
@else
<div class="tscroll"><table class="t stack-sm"><thead><tr><th>Invoice</th><th>Issued</th><th>Due</th><th>Status</th><th class="right">Total</th><th class="right"><span class="sr-only">Action</span></th></tr></thead><tbody>
@foreach ($invoices as $i)
<tr>
    <td data-label="Invoice"><a href="{{ route('portal.invoices.show', $i) }}"><b>{{ $i->displayNumber() }}</b></a></td>
    <td data-label="Issued">{{ $i->issue_date?->format('j M Y') }}</td>
    <td data-label="Due">{{ $i->due_date?->format('j M Y') ?? '—' }}</td>
    <td data-label="Status"><x-portal-invoice-status :invoice="$i" /></td>
    <td class="right num" data-label="Total">{{ money($i->total, $i->currency) }}@if ($i->isPayable() && $i->amount_paid)<div class="muted small">{{ money($i->balance(), $i->currency) }} due</div>@endif</td>
    <td class="right" data-label="Action">@if ($i->isPayable())<a class="btn sm primary" href="{{ route('portal.invoices.show', $i) }}">Pay {{ money($i->balance(), $i->currency) }}</a>@else<a class="btn sm" href="{{ route('portal.invoices.show', $i) }}">{{ $i->status === \App\Enums\InvoiceStatus::Paid ? 'Receipt' : 'View' }}</a>@endif</td>
</tr>
@endforeach</tbody></table></div>
<div class="pager-wrap">{{ $invoices->links() }}</div>
@endif
</x-card>
@endsection
