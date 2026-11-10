@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Invoice '.$invoice->displayNumber())
@push('head') @include('admin.partials.filters-style') @endpush
@section('content')
@php
    $st = $invoice->status->value;
    $open = $st === 'open';
    $canPay = in_array($st, ['open', 'uncollectible']) && $invoice->balance() > 0;
    $overdue = $invoice->isOverdue();
    $late = $overdue ? (int) $invoice->due_date->copy()->startOfDay()->diffInDays(today()) : 0;
    $plural = fn ($n) => $n.' '.\Illuminate\Support\Str::plural('day', $n);
    $statusNote = match (true) {
        $st === 'paid' && $invoice->paid_at !== null => 'Paid '.$invoice->paid_at->format('j M Y'),
        $st === 'draft' => 'Not yet sent to the client',
        $st === 'uncollectible' => 'Written off, payments still accepted',
        $st === 'void' => 'Cannot be paid',
        $invoice->sent_at !== null => 'Emailed '.$invoice->sent_at->format('j M Y'),
        default => '',
    };
    $dueNote = '';
    if ($overdue) { $dueNote = $plural($late).' late'; }
    elseif ($open && $invoice->due_date) {
        $left = (int) today()->diffInDays($invoice->due_date->copy()->startOfDay(), false);
        $dueNote = $left === 0 ? 'Due today' : ($left > 0 ? 'In '.$plural($left) : $plural(-$left).' ago');
    }
    // Newest first: issue, email, payments and refunds in one list.
    $events = collect();
    foreach ($invoice->payments as $p) {
        $events->push(['at' => $p->paid_at ?? $p->created_at, 'kind' => $p->isRefund() ? 'refund' : 'payment', 'payment' => $p]);
    }
    if ($invoice->sent_at) { $events->push(['at' => $invoice->sent_at, 'kind' => 'sent']); }
    if ($invoice->issue_date) { $events->push(['at' => $invoice->issue_date->copy()->startOfDay(), 'kind' => 'issued']); }
    $events = $events->sortByDesc(fn ($e) => $e['at']->timestamp)->values();
@endphp
<x-page-head :title="'Invoice '.$invoice->displayNumber()" :sub="$invoice->client->displayName()" :back="route('admin.invoices.index')" back-label="Invoices">
    @if ($st === 'draft')
        <a class="btn" href="{{ route('admin.invoices.edit', $invoice) }}"><x-icon name="pencil" :size="15" /> Edit</a>
        <a class="btn" href="{{ route('admin.invoices.pdf', $invoice) }}"><x-icon name="download" :size="15" /> PDF</a>
        <form method="POST" action="{{ route('admin.invoices.issue', $invoice) }}" data-confirm="Issue {{ $invoice->displayNumber() }} and email it to {{ $invoice->client->email }}?" data-confirm-label="Issue and email">@csrf<button class="btn primary" type="submit"><x-icon name="send" :size="15" /> Issue and email</button></form>
    @elseif ($canPay)
        <a class="btn" href="{{ route('admin.invoices.pdf', $invoice) }}"><x-icon name="download" :size="15" /> PDF</a>
        <a class="btn primary" href="#record-payment" data-focus="#f-amount"><x-icon name="wallet" :size="15" /> Record payment</a>
    @else
        <a class="btn primary" href="{{ route('admin.invoices.pdf', $invoice) }}"><x-icon name="download" :size="15" /> Download PDF</a>
    @endif
    <x-menu label="More">
            <a class="item" role="menuitem" href="javascript:window.print()">Print</a>
            @if ($st !== 'draft')<button class="item" role="menuitem" type="button" data-copy="{{ route('invoice.public', $invoice->uuid) }}">Copy public link</button>@endif
            @if ($open)
                <form method="POST" action="{{ route('admin.invoices.resend', $invoice) }}">@csrf<button class="item" role="menuitem" type="submit">Resend email</button></form>
                <form method="POST" action="{{ route('admin.invoices.uncollectible', $invoice) }}" data-confirm="Mark {{ $invoice->displayNumber() }} as uncollectible? You can still record a payment later." data-confirm-label="Mark uncollectible">@csrf<button class="item" role="menuitem" type="submit">Mark uncollectible</button></form>
            @endif
            @php($canVoid = in_array($st, ['draft', 'open', 'uncollectible']) && $invoice->amount_paid == 0)
            @if ($st === 'draft' || $canVoid)
                <hr>
                @if ($st === 'draft')
                    <form method="POST" action="{{ route('admin.invoices.destroy', $invoice) }}" data-confirm="Delete draft {{ $invoice->displayNumber() }} for {{ $invoice->client->displayName() }}? This cannot be undone." data-danger data-confirm-label="Delete draft">@csrf @method('DELETE')<button class="item danger" role="menuitem" type="submit">Delete draft</button></form>
                @endif
                @if ($canVoid && $st !== 'draft')
                    <form method="POST" action="{{ route('admin.invoices.void', $invoice) }}" data-confirm="Void {{ $invoice->displayNumber() }}? Clients can no longer pay it." data-danger data-confirm-label="Void invoice">@csrf<button class="item danger" role="menuitem" type="submit">Void invoice</button></form>
                @endif
            @endif
    </x-menu>
</x-page-head>
@if (app(\App\Services\PdfRenderer::class)->needsCjkFonts($invoice))
<div class="alert mb-16" role="status">This invoice contains Chinese, Japanese or Korean text, but no PDF font pack is installed, so the PDF shows empty boxes. <a href="{{ route('admin.settings.index', 'system') }}">Install the font packs</a> (<code>php artisan billing:fonts:install</code>).</div>
@endif

<section class="card sumhead" aria-label="Invoice summary">
    <div><span class="k">Status</span><span class="v">@if ($overdue)<span class="pill bad">Overdue</span>@else<x-status :status="$invoice->status" />@endif</span>
        <span class="s">{{ $statusNote }}</span></div>
    <div><span class="k">{{ in_array($st, ['paid', 'void']) ? 'Balance' : 'Amount due' }}</span><span class="v big {{ $overdue ? 'bad-text' : '' }}">{{ $st === 'void' ? '—' : money(in_array($st, ['draft']) ? $invoice->total : $invoice->balance(), $invoice->currency) }}</span>
        <span class="s">{{ $invoice->currency }}@if ($invoice->amount_paid && $st !== 'paid'), {{ money($invoice->amount_paid, $invoice->currency) }} paid so far @endif</span></div>
    <div><span class="k">Due date</span><span class="v {{ $overdue ? 'bad-text' : '' }}">{{ $invoice->due_date?->format('j M Y') ?? '—' }}</span>
        <span class="s {{ $overdue ? 'bad-text' : '' }}">{{ $dueNote }}</span></div>
    <div><span class="k">Invoice total</span><span class="v">{{ money($invoice->total, $invoice->currency) }}</span>
        <span class="s">{{ $invoice->issue_date ? 'Issued '.$invoice->issue_date->format('j M Y') : 'No issue date yet' }}</span></div>
</section>

<div class="split-layout">
    <div class="stack">
        @include('partials.invoice-document')
        {!! extensions('admin.invoice', ['invoice' => $invoice]) !!}
    </div>
    <aside>
        @if ($canPay)
        <x-card title="Record a payment" id="record-payment">
            <form method="POST" action="{{ route('admin.invoices.payments.store', $invoice) }}" class="stack sm">@csrf
                <x-field name="amount" label="Amount received" type="number" step="any" min="0" :prefix="$invoice->currency" :value="\App\Support\Money::toDecimal($invoice->balance(), $invoice->currency)" help="Balance due: {{ money($invoice->balance(), $invoice->currency) }}." aria-required="true" />
                <x-field name="method" label="Payment method" value="Bank transfer" help="For example bank transfer, cheque or cash." aria-required="true" />
                <x-field name="notes" label="Notes (optional)" />
                <button class="btn primary" type="submit">Record payment</button>
            </form>
        </x-card>
        @endif
        <x-card title="Activity">
            @if ($events->isEmpty())<p class="muted small">Nothing has happened on this invoice yet.</p>@else
            <ol class="timeline">
                @foreach ($events as $e)
                    @php($p = $e['payment'] ?? null)
                    <li class="tl {{ $e['kind'] }}">
                        <span class="tl-dot" aria-hidden="true"></span>
                        <div class="tl-b">
                            @if ($p)
                                <div class="tl-t"><b class="num {{ $e['kind'] === 'refund' ? 'refund-amt' : '' }}">{{ $e['kind'] === 'refund' ? '−' : '' }}{{ money($p->amount, $p->currency) }}</b> {{ $e['kind'] === 'refund' ? 'refunded' : 'paid' }}@if ($p->status->value !== 'completed') <x-status :status="$p->status" />@endif</div>
                                <div class="muted small">{{ ucfirst($p->gateway) }} · {{ $e['at']->format('j M Y, H:i') }}</div>
                                @if ($p->notes)<div class="muted small">{{ $p->notes }}</div>@endif
                                @if (($refundable[$p->id] ?? 0) > 0)
                                    <details class="tl-refund"><summary class="small">Refund…</summary>
                                        <form method="POST" action="{{ route('admin.payments.refund', $p) }}" class="stack sm" data-confirm="Refund this {{ money($p->amount, $p->currency) }} payment on {{ $invoice->displayNumber() }}? Card payments are returned through the gateway." data-danger data-confirm-label="Refund">@csrf
                                            <label for="refund-{{ $p->id }}"><span>Amount to refund (optional)</span><input id="refund-{{ $p->id }}" type="number" step="any" min="0" name="amount" placeholder="{{ \App\Support\Money::toDecimal($refundable[$p->id], $p->currency) }}"><span class="help">Leave blank to refund the full {{ money($refundable[$p->id], $p->currency) }} left.</span></label>
                                            <label for="reason-{{ $p->id }}"><span>Reason (optional)</span><input id="reason-{{ $p->id }}" name="reason" maxlength="255"></label>
                                            <button class="btn danger sm" type="submit">Refund payment</button>
                                        </form></details>
                                @endif
                            @elseif ($e['kind'] === 'sent')
                                <div class="tl-t">Emailed to {{ $invoice->client->email }}</div><div class="muted small">{{ $e['at']->format('j M Y, H:i') }}</div>
                            @else
                                <div class="tl-t">Invoice issued</div><div class="muted small">{{ $e['at']->format('j M Y') }}</div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
            @endif
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
            <x-card title="From quote"><a class="link-underline" href="{{ route('admin.quotes.show', $invoice->quote) }}">{{ $invoice->quote->displayNumber() }}</a></x-card>
        @endif
    </aside>
</div>
@endsection
@push('scripts')
<script>
document.querySelectorAll('[aria-required="true"]').forEach(function (e) { e.required = true; });
</script>
@endpush
