@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Quote '.$quote->displayNumber())
@push('head') @include('admin.partials.filters-style') @endpush
@section('content')
@php
    $st = $quote->status->value;
    $canConvert = in_array($st, ['draft', 'sent', 'accepted']) && ! $quote->invoice_id;
    $stale = $st === 'sent' && $quote->isPastValidity();
    $events = collect();
    if ($quote->accepted_at) { $events->push(['at' => $quote->accepted_at, 'text' => 'Accepted'.($quote->accepted_by ? ' by '.$quote->accepted_by : '')]); }
    if ($quote->declined_at) { $events->push(['at' => $quote->declined_at, 'text' => 'Declined']); }
    if ($quote->sent_at) { $events->push(['at' => $quote->sent_at, 'text' => 'Emailed to '.$quote->client->email]); }
    $events = $events->sortByDesc(fn ($e) => $e['at']->timestamp)->values();
@endphp
<x-page-head :title="'Quote '.$quote->displayNumber()" :sub="$quote->client->displayName().($quote->title ? ' · '.$quote->title : '')" :back="route('admin.quotes.index')" back-label="Quotes">
    @if ($st === 'draft')
        <a class="btn" href="{{ route('admin.quotes.edit', $quote) }}"><x-icon name="pencil" :size="15" /> Edit</a>
        <a class="btn" href="{{ route('admin.quotes.pdf', $quote) }}"><x-icon name="download" :size="15" /> PDF</a>
        <form method="POST" action="{{ route('admin.quotes.send', $quote) }}" data-confirm="Send {{ $quote->displayNumber() }} to {{ $quote->client->email }}?" data-confirm-label="Send quote">@csrf<button class="btn primary" type="submit"><x-icon name="send" :size="15" /> Send to client</button></form>
    @elseif ($st === 'sent')
        <a class="btn" href="{{ route('admin.quotes.pdf', $quote) }}"><x-icon name="download" :size="15" /> PDF</a>
        <form method="POST" action="{{ route('admin.quotes.accept', $quote) }}" data-confirm="Mark {{ $quote->displayNumber() }} as accepted on {{ $quote->client->displayName() }}'s behalf? An invoice is created straight away." data-confirm-label="Mark accepted">@csrf<button class="btn primary" type="submit">Mark accepted</button></form>
    @elseif ($canConvert)
        <a class="btn" href="{{ route('admin.quotes.pdf', $quote) }}"><x-icon name="download" :size="15" /> PDF</a>
        <form method="POST" action="{{ route('admin.quotes.convert', $quote) }}" data-confirm="Convert {{ $quote->displayNumber() }} to an invoice now? Product lines create pending services." data-confirm-label="Convert">@csrf<button class="btn primary" type="submit"><x-icon name="receipt" :size="15" /> Convert to invoice</button></form>
    @else
        <a class="btn primary" href="{{ route('admin.quotes.pdf', $quote) }}"><x-icon name="download" :size="15" /> Download PDF</a>
    @endif
    <x-menu label="More">
            <a class="item" role="menuitem" href="javascript:window.print()">Print</a>
            @if ($st !== 'draft')<button class="item" role="menuitem" type="button" data-copy="{{ route('quote.public', $quote->uuid) }}">Copy public link</button>@endif
            <form method="POST" action="{{ route('admin.quotes.duplicate', $quote) }}">@csrf<button class="item" role="menuitem" type="submit">Duplicate</button></form>
            @if ($st === 'sent')<form method="POST" action="{{ route('admin.quotes.send', $quote) }}">@csrf<button class="item" role="menuitem" type="submit">Resend email</button></form>@endif
            @if ($canConvert && $st !== 'accepted' && $st !== 'draft')
                <form method="POST" action="{{ route('admin.quotes.convert', $quote) }}" data-confirm="Convert {{ $quote->displayNumber() }} to an invoice now? Product lines create pending services." data-confirm-label="Convert">@csrf<button class="item" role="menuitem" type="submit">Convert to invoice</button></form>
            @elseif ($canConvert && $st === 'draft')
                <form method="POST" action="{{ route('admin.quotes.convert', $quote) }}" data-confirm="Convert {{ $quote->displayNumber() }} to an invoice now, without sending it? Product lines create pending services." data-confirm-label="Convert">@csrf<button class="item" role="menuitem" type="submit">Convert to invoice</button></form>
            @endif
            @if ($st === 'sent')<form method="POST" action="{{ route('admin.quotes.decline', $quote) }}" data-confirm="Mark {{ $quote->displayNumber() }} as declined?" data-confirm-label="Mark declined">@csrf<button class="item" role="menuitem" type="submit">Mark declined</button></form>@endif
            @if ($st === 'draft')
                <hr>
                <form method="POST" action="{{ route('admin.quotes.destroy', $quote) }}" data-confirm="Delete draft {{ $quote->displayNumber() }} for {{ $quote->client->displayName() }}? This cannot be undone." data-danger data-confirm-label="Delete draft">@csrf @method('DELETE')<button class="item danger" role="menuitem" type="submit">Delete draft</button></form>
            @endif
    </x-menu>
</x-page-head>
@if (app(\App\Services\PdfRenderer::class)->needsCjkFonts($quote))
<div class="alert mb-16" role="status">This quote contains Chinese, Japanese or Korean text, but no PDF font pack is installed, so the PDF shows empty boxes. <a href="{{ route('admin.settings.index', 'system') }}">Install the font packs</a> (<code>php artisan billing:fonts:install</code>).</div>
@endif

<section class="card sumhead" aria-label="Quote summary">
    <div><span class="k">Status</span><span class="v"><x-status :status="$quote->status" /></span>
        <span class="s">@if ($st === 'draft')Not yet sent to the client @elseif ($st === 'sent')Waiting for the client @elseif ($st === 'converted' || $quote->invoice_id)Turned into an invoice @elseif ($st === 'accepted')Accepted, ready to convert @elseif ($st === 'declined')The client said no @else Validity ended @endif</span></div>
    <div><span class="k">Quote total</span><span class="v big">{{ money($quote->total, $quote->currency) }}</span><span class="s">{{ $quote->currency }}</span></div>
    <div><span class="k">Valid until</span><span class="v {{ $stale ? 'bad-text' : '' }}">{{ $quote->valid_until?->format('j M Y') ?? 'No end date' }}</span><span class="s {{ $stale ? 'bad-text' : '' }}">@if ($stale)Past its date, client can no longer accept @endif</span></div>
    <div><span class="k">Invoice</span><span class="v">@if ($quote->invoice)<a class="link-underline" href="{{ route('admin.invoices.show', $quote->invoice) }}">{{ $quote->invoice->displayNumber() }}</a>@else—@endif</span><span class="s">@if ($quote->invoice){{ $quote->invoice->isOverdue() ? 'Overdue' : $quote->invoice->status->label() }}@else Created when accepted @endif</span></div>
</section>

<div class="split-layout">
    <div class="stack">@include('partials.quote-document')</div>
    <aside>
        <x-card title="Activity">
            @if ($events->isEmpty())<p class="muted small">Not sent yet. Nothing has happened on this quote.</p>@else
            <ol class="timeline">@foreach ($events as $e)
                <li class="tl"><span class="tl-dot" aria-hidden="true"></span><div class="tl-b"><div class="tl-t">{{ $e['text'] }}</div><div class="muted small">{{ $e['at']->format('j M Y, H:i') }}</div></div></li>
            @endforeach</ol>
            @endif
        </x-card>
    </aside>
</div>
@endsection
