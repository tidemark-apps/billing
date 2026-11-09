{{-- The printable invoice. Needs $invoice with client, items and payments loaded. Shared by admin, portal and public pages. --}}
<article class="card doc">
    <header class="doc-head">
        @include('partials.company-block')
        <div class="doc-meta">
            <h2>Invoice {{ $invoice->displayNumber() }}</h2>
            <x-status :status="$invoice->status" />
            <dl class="kv">
                @if ($invoice->issue_date)<dt>Issued</dt><dd>{{ $invoice->issue_date->format('j M Y') }}</dd>@endif
                @if ($invoice->due_date)<dt>Due</dt><dd>{{ $invoice->due_date->format('j M Y') }}@if($invoice->isOverdue()) <span class="pill bad">Overdue</span>@endif</dd>@endif
                @if ($invoice->reference)<dt>Reference</dt><dd>{{ $invoice->reference }}</dd>@endif
            </dl>
        </div>
    </header>
    <div class="doc-bill">
        <div class="muted small">Bill to</div>
        <b>{{ $invoice->client->displayName() }}</b>
        @foreach ($invoice->client->addressLines() as $line)<div class="muted small">{{ $line }}</div>@endforeach
        @if ($invoice->client->tax_id)<div class="muted small">Tax ID: {{ $invoice->client->tax_id }}</div>@endif
    </div>
    <div class="tscroll">
        <table class="t doc-items">
            <thead><tr><th>Description</th><th class="right">Qty</th><th class="right">Unit price</th><th class="right">Amount</th></tr></thead>
            <tbody>@foreach ($invoice->items as $item)
                <tr><td>{{ $item->description }}</td><td class="right">{{ rtrim(rtrim(number_format($item->quantity, 3), '0'), '.') }}</td><td class="right">{{ money($item->unit_price, $invoice->currency) }}</td><td class="right">{{ money($item->total, $invoice->currency) }}</td></tr>
            @endforeach</tbody>
        </table>
    </div>
    <div class="doc-totals">
        <div><span>Subtotal</span><span>{{ money($invoice->subtotal, $invoice->currency) }}</span></div>
        @if ($invoice->tax_total || $invoice->tax_rate)<div><span>{{ $invoice->tax_name ?: 'Tax' }} ({{ rtrim(rtrim(number_format($invoice->tax_rate, 3), '0'), '.') }}%)</span><span>{{ money($invoice->tax_total, $invoice->currency) }}</span></div>@endif
        <div class="total"><span>Total</span><span>{{ money($invoice->total, $invoice->currency) }}</span></div>
        @if ($invoice->amount_paid)<div><span>Paid</span><span>−{{ money($invoice->amount_paid, $invoice->currency) }}</span></div><div class="total"><span>Balance due</span><span>{{ money($invoice->balance(), $invoice->currency) }}</span></div>@endif
    </div>
    @if ($invoice->notes || $invoice->terms)
        <footer class="doc-foot">
            @if ($invoice->notes)<div><b>Notes</b><p class="muted small" style="white-space:pre-line">{{ $invoice->notes }}</p></div>@endif
            @if ($invoice->terms)<div><b>Terms</b><p class="muted small" style="white-space:pre-line">{{ $invoice->terms }}</p></div>@endif
        </footer>
    @endif
</article>
