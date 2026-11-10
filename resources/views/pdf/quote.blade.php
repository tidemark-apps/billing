@php
    $qty = fn ($n) => rtrim(rtrim(number_format($n, 3), '0'), '.');
    $status = $quote->status->value;
    $stamp = match ($status) { 'accepted' => 'Accepted', 'converted' => 'Accepted', 'declined' => 'Declined', 'expired' => 'Expired', 'draft' => 'Draft', default => null };
    $stampTone = match ($stamp) { 'Accepted' => 'good', 'Declined', 'Expired' => 'bad', default => '' };
    $meta = array_filter([
        'Date' => $quote->issue_date?->format('j M Y'),
        'Valid until' => $quote->valid_until?->format('j M Y'),
    ]);
@endphp
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Quote {{ $quote->displayNumber() }}</title>@include('pdf._style')</head>
<body>
@include('pdf._head', ['docType' => 'Quote', 'number' => $quote->displayNumber(), 'toLabel' => 'Prepared for', 'client' => $quote->client, 'subject' => $quote->title])
<table class="items">
    <thead><tr><th style="width:50%">Description</th><th>Unit cost</th><th class="right qty">Qty</th><th class="right">Amount</th></tr></thead>
    <tbody>@foreach ($quote->items as $item)
        <tr><td class="pre">{{ $item->description }}@if ($item->billing_cycle) <span class="muted small">&middot; {{ $item->billing_cycle->label() }}</span>@endif</td><td class="nowrap">{{ money($item->unit_price, $quote->currency) }}</td><td class="qty">{{ $qty($item->quantity) }}</td><td class="right">{{ money($item->total, $quote->currency) }}</td></tr>
    @endforeach</tbody>
</table>
<table class="totals">
    <tr><td>Subtotal</td><td class="right">{{ money($quote->subtotal, $quote->currency) }}</td></tr>
    @if ($quote->tax_total || $quote->tax_rate)<tr><td>{{ $quote->tax_name ?: 'Tax' }} ({{ $qty($quote->tax_rate) }}%)</td><td class="right">{{ money($quote->tax_total, $quote->currency) }}</td></tr>@endif
    <tr class="total"><td>Total</td><td class="right">{{ money($quote->total, $quote->currency) }}</td></tr>
</table>
@if ($quote->notes)<div class="box"><b>Notes</b><div class="muted small pre">{{ $quote->notes }}</div></div>@endif
@if ($quote->terms)<div class="box"><b>Terms</b><div class="muted small pre">{{ $quote->terms }}</div></div>@endif
@include('pdf._foot', ['thanks' => 'Thank you for considering us!'])
</body></html>
