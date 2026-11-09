@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Quote '.$quote->displayNumber())
@push('head') @include('admin.partials.filters-style') @endpush
@section('content')
@php($st = $quote->status->value)
<x-page-head :title="'Quote '.$quote->displayNumber()" :sub="$quote->client->displayName()" :back="route('admin.quotes.index')" back-label="Quotes">
    @if ($st === 'draft')<a class="btn" href="{{ route('admin.quotes.edit', $quote) }}"><x-icon name="pencil" :size="15" /> Edit</a>@endif
    <a class="btn" href="{{ route('admin.quotes.pdf', $quote) }}"><x-icon name="download" :size="15" /> Download PDF</a>
    <a class="btn" href="javascript:window.print()"><x-icon name="download" :size="15" /> Print</a>
</x-page-head>
@if (app(\App\Services\PdfRenderer::class)->needsCjkFonts($quote))
<div class="alert" role="status" style="margin-bottom:12px">This quote contains Chinese, Japanese or Korean text, but no PDF font pack is installed, so the PDF shows empty boxes. <a href="{{ route('admin.settings.index', 'system') }}">Install the font packs</a> (<code>php artisan billing:fonts:install</code>).</div>
@endif
<div class="split-layout">
    <div class="stack">@include('partials.quote-document')</div>
    <aside>
        <x-card title="Actions">
            <div class="stack-actions">
                @if (in_array($st, ['draft', 'sent']))<form method="POST" action="{{ route('admin.quotes.send', $quote) }}">@csrf<button class="btn primary" type="submit"><x-icon name="send" :size="15" /> {{ $st === 'draft' ? 'Send to client' : 'Resend email' }}</button></form>@endif
                @if ($st === 'sent')
                    <form method="POST" action="{{ route('admin.quotes.accept', $quote) }}" data-confirm="Mark this quote as accepted on the client's behalf? An invoice is created straight away.">@csrf<button class="btn" type="submit">Mark accepted</button></form>
                    <form method="POST" action="{{ route('admin.quotes.decline', $quote) }}" data-confirm="Mark this quote as declined?">@csrf<button class="btn" type="submit">Mark declined</button></form>
                @endif
                @if (in_array($st, ['draft', 'sent', 'accepted']) && ! $quote->invoice_id)
                    <form method="POST" action="{{ route('admin.quotes.convert', $quote) }}" data-confirm="Convert to an invoice now? Product lines create pending services.">@csrf<button class="btn" type="submit"><x-icon name="receipt" :size="15" /> Convert to invoice</button></form>
                @endif
                @if ($st !== 'draft')<button class="btn" type="button" data-copy="{{ route('quote.public', $quote->uuid) }}"><x-icon name="link" :size="15" /> Copy public link</button>@endif
                <form method="POST" action="{{ route('admin.quotes.duplicate', $quote) }}">@csrf<button class="btn" type="submit"><x-icon name="copy" :size="15" /> Duplicate</button></form>
                @if ($st === 'draft')<form method="POST" action="{{ route('admin.quotes.destroy', $quote) }}" data-confirm="Delete this draft quote? This cannot be undone." data-danger data-confirm-label="Delete">@csrf @method('DELETE')<button class="btn danger" type="submit">Delete draft</button></form>@endif
            </div>
        </x-card>
        <x-card title="Status">
            <dl class="kv">
                <dt>Status</dt><dd><x-status :status="$quote->status" /></dd>
                @if ($quote->sent_at)<dt>Sent</dt><dd>{{ $quote->sent_at->format('j M Y') }}</dd>@endif
                @if ($quote->accepted_at)<dt>Accepted</dt><dd>{{ $quote->accepted_at->format('j M Y') }}@if($quote->accepted_by)<div class="muted small">by {{ $quote->accepted_by }}</div>@endif</dd>@endif
                @if ($quote->declined_at)<dt>Declined</dt><dd>{{ $quote->declined_at->format('j M Y') }}</dd>@endif
            </dl>
            @if ($quote->invoice)<p style="margin-top:12px"><a href="{{ route('admin.invoices.show', $quote->invoice) }}" style="text-decoration:underline">Invoice {{ $quote->invoice->displayNumber() }}</a> <x-status :status="$quote->invoice->status" /></p>@endif
        </x-card>
    </aside>
</div>
@endsection
