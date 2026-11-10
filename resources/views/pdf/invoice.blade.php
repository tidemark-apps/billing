@php
    $qty = fn ($n) => rtrim(rtrim(number_format($n, 3), '0'), '.');
    $status = $invoice->status->value;
    $stamp = match ($status) { 'paid' => 'Paid', 'void' => 'Void', 'uncollectible' => 'Uncollectible', 'draft' => 'Draft', default => $invoice->isOverdue() ? 'Overdue' : null };
    $stampTone = match ($stamp) { 'Paid' => 'good', 'Overdue', 'Uncollectible' => 'bad', default => '' };
    $meta = array_filter([
        'Date' => $invoice->issue_date?->format('j M Y'),
        'Due' => $invoice->due_date?->format('j M Y'),
        'Reference' => $invoice->reference,
    ]);
@endphp
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Invoice {{ $invoice->displayNumber() }}</title>@include('pdf._style')</head>
<body>
@include('pdf._head', ['docType' => 'Invoice', 'number' => $invoice->displayNumber(), 'toLabel' => 'Issued to', 'client' => $invoice->client])
<table class="items">
    <thead><tr><th style="width:50%">Description</th><th>Unit cost</th><th class="right qty">Qty</th><th class="right">Amount</th></tr></thead>
    <tbody>@foreach ($invoice->items as $item)
        <tr><td class="pre">{{ $item->description }}</td><td class="nowrap">{{ money($item->unit_price, $invoice->currency) }}</td><td class="qty">{{ $qty($item->quantity) }}</td><td class="right">{{ money($item->total, $invoice->currency) }}</td></tr>
    @endforeach</tbody>
</table>
<table class="totals">
    <tr><td>Subtotal</td><td class="right">{{ money($invoice->subtotal, $invoice->currency) }}</td></tr>
    @if ($invoice->tax_total || $invoice->tax_rate)<tr><td>{{ $invoice->tax_name ?: 'Tax' }} ({{ $qty($invoice->tax_rate) }}%)</td><td class="right">{{ money($invoice->tax_total, $invoice->currency) }}</td></tr>@endif
    <tr class="total"><td>Total</td><td class="right">{{ money($invoice->total, $invoice->currency) }}</td></tr>
    @if ($invoice->amount_paid)
        <tr><td>Paid</td><td class="right">-{{ money($invoice->amount_paid, $invoice->currency) }}</td></tr>
        <tr class="due"><td>Balance due</td><td class="right">{{ money($invoice->balance(), $invoice->currency) }}</td></tr>
    @endif
</table>
@if ($payUrl)<div class="box"><b>Pay online</b><div class="small">Pay securely at <span class="paylink">{{ $payUrl }}</span></div></div>@endif
@if ($paymentInstructions !== '' && $status !== 'paid' && $status !== 'void')<div class="box"><b>Payment instructions</b><div class="muted small pre">{{ $paymentInstructions }}</div></div>@endif
@if ($invoice->notes)<div class="box"><b>Notes</b><div class="muted small pre">{{ $invoice->notes }}</div></div>@endif
@if ($invoice->terms)<div class="box"><b>Terms</b><div class="muted small pre">{{ $invoice->terms }}</div></div>@endif
@include('pdf._foot', ['thanks' => $status === 'paid' ? 'Thank you for your payment!' : 'Thank you for your business!'])
</body></html>
