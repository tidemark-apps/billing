@extends('layouts.public')
@section('title', 'Quote '.$quote->displayNumber())
@section('content')
<div class="narrow-doc stack">
    <x-page-head :title="'Quote '.$quote->displayNumber()"><a class="btn" href="{{ route('quote.public.pdf', $quote->uuid) }}"><x-icon name="download" :size="15" /> Download PDF</a><a class="btn" href="javascript:window.print()"><x-icon name="download" :size="15" /> Print / save PDF</a></x-page-head>
    @if ($quote->isOpenForResponse())
        <section class="card pad">
            <form method="POST" action="{{ route('quote.respond', $quote->uuid) }}" class="stack sm">@csrf
                <b>Ready to go ahead?</b>
                <x-field name="name" label="Type your full name to accept" placeholder="Full name" />
                <div class="row"><button class="btn primary" name="decision" value="accept" type="submit">Accept quote</button><button class="btn" name="decision" value="decline" type="submit" data-confirm-skip>Decline</button></div>
            </form>
        </section>
    @elseif ($quote->status->value === 'sent')<div class="notice warn"><x-icon name="triangle-alert" :size="16" /><div>This quote has expired. Contact us for an updated one.</div></div>
    @elseif ($quote->invoice)<div class="notice good"><x-icon name="circle-check" :size="16" /><div>Accepted. <a href="{{ route('invoice.public', $quote->invoice->uuid) }}" style="text-decoration:underline">View the invoice</a>.</div></div>@endif
    @include('partials.quote-document')
</div>
@endsection
