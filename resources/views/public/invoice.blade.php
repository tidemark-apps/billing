@extends('layouts.public')
@section('title', 'Invoice '.$invoice->displayNumber())
@section('content')
<div class="narrow-doc stack">
    <x-portal-invoice-pay :invoice="$invoice" :gateways="$gateways" :titles="$titles" />
    <x-page-head :title="'Invoice '.$invoice->displayNumber()">
        <a class="btn" href="{{ route('invoice.public.pdf', $invoice->uuid) }}"><x-icon name="download" :size="15" /> {{ $invoice->status === \App\Enums\InvoiceStatus::Paid ? 'Download receipt (PDF)' : 'Download PDF' }}</a>
        <button class="btn" type="button" onclick="window.print()">Print</button>
    </x-page-head>
    @include('partials.invoice-document')
    @if ($invoice->payments->isNotEmpty())
        <x-card title="Payments" :flush="true"><div class="tscroll"><table class="t"><thead><tr><th>Date</th><th>Method</th><th class="right">Amount</th></tr></thead><tbody>
            @foreach ($invoice->payments as $p)<tr><td>{{ $p->paid_at?->format('j M Y') }}</td><td>{{ $p->isRefund() ? 'Refund · ' : '' }}{{ ucfirst($p->gateway) }}</td><td class="right num">{{ $p->isRefund() ? '−' : '' }}{{ money($p->amount, $p->currency) }}</td></tr>@endforeach
        </tbody></table></div></x-card>
    @endif
</div>
@endsection
