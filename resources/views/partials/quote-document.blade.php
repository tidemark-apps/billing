{{-- The printable quote. Needs $quote with client and items loaded. --}}
<article class="card doc">
    <header class="doc-head">
        @include('partials.company-block')
        <div class="doc-meta">
            <h2>Quote {{ $quote->displayNumber() }}</h2>
            <x-status :status="$quote->status" />
            <dl class="kv">
                @if ($quote->issue_date)<dt>Issued</dt><dd>{{ $quote->issue_date->format('j M Y') }}</dd>@endif
                @if ($quote->valid_until)<dt>Valid until</dt><dd>{{ $quote->valid_until->format('j M Y') }}</dd>@endif
            </dl>
        </div>
    </header>
    <div class="doc-bill">
        <div class="muted small">Prepared for</div>
        <b>{{ $quote->client->displayName() }}</b>
        @foreach ($quote->client->addressLines() as $line)<div class="muted small">{{ $line }}</div>@endforeach
        @if ($quote->title)<div style="margin-top:12px"><b>{{ $quote->title }}</b></div>@endif
    </div>
    <div class="tscroll">
        <table class="t doc-items">
            <thead><tr><th>Description</th><th class="right">Qty</th><th class="right">Unit price</th><th class="right">Amount</th></tr></thead>
            <tbody>@foreach ($quote->items as $item)
                <tr><td>{{ $item->description }}@if($item->billing_cycle) <span class="muted small">· {{ $item->billing_cycle->label() }}</span>@endif</td><td class="right">{{ rtrim(rtrim(number_format($item->quantity, 3), '0'), '.') }}</td><td class="right">{{ money($item->unit_price, $quote->currency) }}</td><td class="right">{{ money($item->total, $quote->currency) }}</td></tr>
            @endforeach</tbody>
        </table>
    </div>
    <div class="doc-totals">
        <div><span>Subtotal</span><span>{{ money($quote->subtotal, $quote->currency) }}</span></div>
        @if ($quote->tax_total || $quote->tax_rate)<div><span>{{ $quote->tax_name ?: 'Tax' }} ({{ rtrim(rtrim(number_format($quote->tax_rate, 3), '0'), '.') }}%)</span><span>{{ money($quote->tax_total, $quote->currency) }}</span></div>@endif
        <div class="total"><span>Total</span><span>{{ money($quote->total, $quote->currency) }}</span></div>
    </div>
    @if ($quote->notes || $quote->terms)
        <footer class="doc-foot">
            @if ($quote->notes)<div><b>Notes</b><p class="muted small" style="white-space:pre-line">{{ $quote->notes }}</p></div>@endif
            @if ($quote->terms)<div><b>Terms</b><p class="muted small" style="white-space:pre-line">{{ $quote->terms }}</p></div>@endif
        </footer>
    @endif
</article>
