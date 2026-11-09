@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Quote '.$quote->displayNumber())
@section('content')
<x-page-head :title="'Quote '.$quote->displayNumber()" :back="route('portal.quotes.index')" back-label="Quotes"><a class="btn" href="{{ route('portal.quotes.pdf', $quote) }}"><x-icon name="download" :size="15" /> Download PDF</a><a class="btn" href="javascript:window.print()"><x-icon name="download" :size="15" /> Print</a></x-page-head>
@if ($quote->isOpenForResponse())
<section class="card pad"><form method="POST" action="{{ route('portal.quotes.respond', $quote) }}" class="stack sm">@csrf
    <b>Ready to go ahead?</b>
    <x-field name="name" label="Type your full name to accept" placeholder="Full name" />
    <div class="row"><button class="btn primary" name="decision" value="accept" type="submit">Accept quote</button><button class="btn" name="decision" value="decline" type="submit">Decline</button></div>
</form></section>
@elseif ($quote->invoice)<div class="notice good"><x-icon name="circle-check" :size="16" /><div>Accepted. <a href="{{ route('portal.invoices.show', $quote->invoice) }}" style="text-decoration:underline">View the invoice</a>.</div></div>
@elseif ($quote->status->value === 'sent')<div class="notice warn"><x-icon name="triangle-alert" :size="16" /><div>This quote has expired.</div></div>@endif
@include('partials.quote-document')
@endsection
