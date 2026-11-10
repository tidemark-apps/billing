@props(['invoice', 'gateways' => [], 'titles' => []])
{{-- Above-the-fold invoice summary: amount due, due date, one status, and the Pay action. On a paid invoice it becomes the receipt. --}}
@php
    $payable = $invoice->isPayable();
    $paid = $invoice->status === \App\Enums\InvoiceStatus::Paid;
    $lastPayment = $invoice->payments->where('amount', '>', 0)->sortByDesc('paid_at')->first();
@endphp
<section class="card pay-hero {{ $invoice->isOverdue() ? 'is-overdue' : ($paid ? 'is-paid' : '') }}" aria-label="Payment summary">
    <div class="pay-hero-main">
        <div class="pay-hero-label">
            @if ($payable)Amount due @elseif ($paid)Paid in full @else Total @endif
            <x-portal-invoice-status :invoice="$invoice" />
        </div>
        <div class="pay-hero-amount num">{{ money($payable ? $invoice->balance() : $invoice->total, $invoice->currency) }}</div>
        <div class="pay-hero-sub muted">
            @if ($payable)
                @if ($invoice->due_date){{ $invoice->isOverdue() ? 'Was due' : 'Due' }} {{ $invoice->due_date->format('j M Y') }}@if ($invoice->isOverdue()) ({{ $invoice->due_date->diffForHumans() }})@endif @else Due on receipt @endif
                · Invoice {{ $invoice->displayNumber() }}
            @elseif ($paid)
                @if ($lastPayment?->paid_at)Received {{ $lastPayment->paid_at->format('j M Y') }}@endif @if ($lastPayment) via {{ ucfirst($lastPayment->gateway) }}@endif · Receipt for invoice {{ $invoice->displayNumber() }}
            @else
                Invoice {{ $invoice->displayNumber() }}
            @endif
        </div>
    </div>
    @if ($payable)
    <div class="pay-hero-actions">
        @if ($gateways)
            <form method="POST" action="{{ route('pay.start', $invoice->uuid) }}" class="pay-hero-gw">@csrf
                @foreach ($gateways as $key => $gw)<button class="btn lg {{ $loop->first ? 'primary' : '' }}" name="gateway" value="{{ $key }}" type="submit"><x-icon name="credit-card" :size="16" /> {{ $loop->first ? 'Pay '.money($invoice->balance(), $invoice->currency).' with '.($titles[$key] ?? $gw->label()) : 'Pay with '.($titles[$key] ?? $gw->label()) }}</button>@endforeach
            </form>
        @else
            <div class="notice"><x-icon name="info" :size="16" /><div>Online payment isn't set up yet. Please contact {{ \App\Support\Settings::get('company.name') ?: 'us' }} to arrange payment.</div></div>
        @endif
        @include('portal.payment-methods._pay-saved', ['invoice' => $invoice])
    </div>
    @endif
</section>
