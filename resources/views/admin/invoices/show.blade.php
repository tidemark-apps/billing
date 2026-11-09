@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Invoice '.$invoice->displayNumber())
@push('head') @include('admin.partials.filters-style') @endpush
@section('content')
@php($open = $invoice->status->value === 'open')
<x-page-head :title="'Invoice '.$invoice->displayNumber()" :sub="$invoice->client->displayName()" :back="route('admin.invoices.index')" back-label="Invoices">
    @if ($invoice->status->value === 'draft')<a class="btn" href="{{ route('admin.invoices.edit', $invoice) }}"><x-icon name="pencil" :size="15" /> Edit</a>@endif
    <a class="btn" href="{{ route('admin.invoices.pdf', $invoice) }}"><x-icon name="download" :size="15" /> Download PDF</a>
    <a class="btn" href="javascript:window.print()"><x-icon name="download" :size="15" /> Print</a>
</x-page-head>
@if (app(\App\Services\PdfRenderer::class)->needsCjkFonts($invoice))
<div class="alert" role="status" style="margin-bottom:12px">This invoice contains Chinese, Japanese or Korean text, but no PDF font pack is installed, so the PDF shows empty boxes. <a href="{{ route('admin.settings.index', 'system') }}">Install the font packs</a> (<code>php artisan billing:fonts:install</code>).</div>
@endif
<div class="split-layout">
    <div class="stack">
        @include('partials.invoice-document')
        {!! extensions('admin.invoice', ['invoice' => $invoice]) !!}
    </div>
    <aside>
        <x-card title="Actions">
            <div class="stack-actions">
                @if ($invoice->status->value === 'draft')
                    <form method="POST" action="{{ route('admin.invoices.issue', $invoice) }}">@csrf<button class="btn primary" type="submit">Issue &amp; email</button></form>
                    <form method="POST" action="{{ route('admin.invoices.destroy', $invoice) }}" data-confirm="Delete this draft? This cannot be undone." data-danger data-confirm-label="Delete">@csrf @method('DELETE')<button class="btn danger" type="submit">Delete draft</button></form>
                @endif
                @if ($open)<form method="POST" action="{{ route('admin.invoices.resend', $invoice) }}">@csrf<button class="btn" type="submit"><x-icon name="send" :size="15" /> Resend email</button></form>@endif
                @if ($invoice->status->value !== 'draft')<button class="btn" type="button" data-copy="{{ route('invoice.public', $invoice->uuid) }}"><x-icon name="link" :size="15" /> Copy public link</button>@endif
                @if ($open)<form method="POST" action="{{ route('admin.invoices.uncollectible', $invoice) }}" data-confirm="Mark this invoice as uncollectible?">@csrf<button class="btn" type="submit">Mark uncollectible</button></form>@endif
                @if (in_array($invoice->status->value, ['draft', 'open', 'uncollectible']) && $invoice->amount_paid == 0)
                    <form method="POST" action="{{ route('admin.invoices.void', $invoice) }}" data-confirm="Void this invoice? Clients can no longer pay it." data-danger data-confirm-label="Void invoice">@csrf<button class="btn danger" type="submit">Void invoice</button></form>
                @endif
                @if ($invoice->status->value === 'paid' || $invoice->status->value === 'void')<p class="muted small">No further actions for a {{ $invoice->status->value }} invoice.</p>@endif
            </div>
        </x-card>
        @if (in_array($invoice->status->value, ['open', 'uncollectible']) && $invoice->balance() > 0)
        <x-card title="Record a payment">
            <form method="POST" action="{{ route('admin.invoices.payments.store', $invoice) }}" class="stack sm">@csrf
                <x-field name="amount" label="Amount" type="number" step="any" min="0" :prefix="$invoice->currency" :value="\App\Support\Money::toDecimal($invoice->balance(), $invoice->currency)" required />
                <x-field name="method" label="Method" value="Bank transfer" required help="Bank transfer, cheque, cash…" />
                <x-field name="notes" label="Notes" />
                <button class="btn primary" type="submit">Record payment</button>
            </form>
        </x-card>
        @endif
        <x-card title="Payments" :flush="true">
            @if ($invoice->payments->isEmpty())<p class="muted small" style="padding:16px 20px">No payments yet.</p>@else
            <table class="t"><tbody>
                @foreach ($invoice->payments as $p)
                    <tr><td><b>{{ $p->isRefund() ? '−' : '' }}{{ money($p->amount, $p->currency) }}</b><div class="muted small">{{ $p->isRefund() ? 'Refund · ' : '' }}{{ ucfirst($p->gateway) }} · {{ $p->paid_at?->format('j M Y') }}</div>
                        @if ($p->notes)<div class="muted small">{{ $p->notes }}</div>@endif
                        @if (($refundable[$p->id] ?? 0) > 0)
                            <details style="margin-top:6px"><summary class="small muted" style="cursor:pointer">Refund…</summary>
                                <form method="POST" action="{{ route('admin.payments.refund', $p) }}" class="stack sm" style="margin-top:8px" data-confirm="Refund this payment? Card payments are returned through the gateway." data-danger data-confirm-label="Refund">@csrf
                                    <x-field :name="'amount'" :label="'Amount (max '.\App\Support\Money::toDecimal($refundable[$p->id], $p->currency).')'" type="number" step="any" min="0" :placeholder="\App\Support\Money::toDecimal($refundable[$p->id], $p->currency)" :id="'refund-'.$p->id" />
                                    <x-field name="reason" label="Reason" :id="'reason-'.$p->id" />
                                    <button class="btn danger sm" type="submit">Refund</button>
                                </form></details>
                        @endif</td></tr>
                @endforeach
            </tbody></table>@endif
        </x-card>
        @if ($services->isNotEmpty())
        <x-card title="Services">
            <div class="stack sm">@foreach ($services as $s)
                @if (Route::has('admin.services.show'))<a href="{{ route('admin.services.show', $s) }}" class="row spread"><span>{{ $s->title() }}</span><x-status :status="$s->status" /></a>
                @else<div class="row spread"><span>{{ $s->title() }}</span><x-status :status="$s->status" /></div>@endif
            @endforeach</div>
        </x-card>
        @endif
        @if ($invoice->quote_id && $invoice->quote)
            <x-card title="From quote"><a href="{{ route('admin.quotes.show', $invoice->quote) }}" style="text-decoration:underline">{{ $invoice->quote->displayNumber() }}</a></x-card>
        @endif
    </aside>
</div>
@endsection
