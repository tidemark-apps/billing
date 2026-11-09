@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Invoice '.$invoice->displayNumber())
@section('content')
<x-page-head :title="'Invoice '.$invoice->displayNumber()" :back="route('portal.invoices.index')" back-label="Invoices"><a class="btn" href="{{ route('portal.invoices.pdf', $invoice) }}"><x-icon name="download" :size="15" /> Download PDF</a><a class="btn" href="javascript:window.print()"><x-icon name="download" :size="15" /> Print</a></x-page-head>
@if ($invoice->isPayable())
<section class="card pad stack sm">
    <b style="font-size:18px">{{ money($invoice->balance(), $invoice->currency) }} due</b>
    @include('portal.payment-methods._pay-saved', ['invoice' => $invoice])
    @if ($gateways)
    <form method="POST" action="{{ route('pay.start', $invoice->uuid) }}" class="row wrap">@csrf
        @foreach ($gateways as $key => $gw)<button class="btn {{ $loop->first ? 'primary' : '' }}" name="gateway" value="{{ $key }}" type="submit"><x-icon name="credit-card" :size="15" /> Pay with {{ $titles[$key] ?? $gw->label() }}</button>@endforeach
    </form>
    @else<div class="notice"><x-icon name="info" :size="16" /><div>Online payment isn't available yet. Please contact us to arrange payment.</div></div>@endif
</section>
@endif
@include('partials.invoice-document')
@if ($invoice->payments->isNotEmpty())
<x-card title="Payments" :flush="true"><table class="t"><thead><tr><th>Date</th><th>Method</th><th class="right">Amount</th></tr></thead><tbody>
@foreach ($invoice->payments as $p)<tr><td>{{ $p->paid_at?->format('j M Y') }}</td><td>{{ $p->isRefund() ? 'Refund · ' : '' }}{{ ucfirst($p->gateway) }}</td><td class="right">{{ $p->isRefund() ? '−' : '' }}{{ money($p->amount, $p->currency) }}</td></tr>@endforeach
</tbody></table></x-card>
@endif
{!! extensions('portal.invoice', ['invoice' => $invoice]) !!}
@endsection
