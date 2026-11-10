@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Quote '.$quote->displayNumber())
@section('content')
<x-page-head :title="'Quote '.$quote->displayNumber()" :back="route('portal.quotes.index')" back-label="Quotes"><a class="btn" href="{{ route('portal.quotes.pdf', $quote) }}"><x-icon name="download" :size="15" /> Download PDF</a><a class="btn" href="javascript:window.print()">Print</a></x-page-head>
@if ($quote->isOpenForResponse())
<x-portal-quote-respond :quote="$quote" :action="route('portal.quotes.respond', $quote)" />
@elseif ($quote->invoice)<div class="notice good"><x-icon name="circle-check" :size="16" /><div>Accepted. <a class="link-underline" href="{{ route('portal.invoices.show', $quote->invoice) }}">View the invoice</a>.</div></div>
@elseif ($quote->status->value === 'sent')<div class="notice warn"><x-icon name="triangle-alert" :size="16" /><div>This quote has expired.</div></div>@endif
@include('partials.quote-document')
@endsection
