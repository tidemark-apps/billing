@extends('layouts.public')
@section('title', 'Quote '.$quote->displayNumber())
@section('content')
<div class="narrow-doc stack">
    <x-page-head :title="'Quote '.$quote->displayNumber()"><a class="btn" href="{{ route('quote.public.pdf', $quote->uuid) }}"><x-icon name="download" :size="15" /> Download PDF</a><a class="btn" href="javascript:window.print()">Print</a></x-page-head>
    @if ($quote->isOpenForResponse())
        <x-portal-quote-respond :quote="$quote" :action="route('quote.respond', $quote->uuid)" />
    @elseif ($quote->status->value === 'sent')<div class="notice warn"><x-icon name="triangle-alert" :size="16" /><div>This quote has expired. Contact us for an updated one.</div></div>
    @elseif ($quote->invoice)<div class="notice good"><x-icon name="circle-check" :size="16" /><div>Accepted. <a class="link-underline" href="{{ route('invoice.public', $quote->invoice->uuid) }}">View the invoice</a>.</div></div>@endif
    @include('partials.quote-document')
</div>
@endsection
