@props(['quote', 'action'])
{{-- Accept / decline panel for a quote. Accept is the one primary action; decline is a separate, confirmed, secondary form. --}}
<section class="card pay-hero" aria-label="Respond to this quote">
    <div class="pay-hero-main">
        <div class="pay-hero-label">Quote total</div>
        <div class="pay-hero-amount num">{{ money($quote->total, $quote->currency) }}</div>
        <div class="muted">@if ($quote->valid_until)Valid until {{ $quote->valid_until->format('j M Y') }} · @endif Accepting creates an invoice for this amount.</div>
    </div>
    <div class="pay-hero-actions">
        <form method="POST" action="{{ $action }}" class="stack sm">@csrf
            <x-field name="name" label="Type your full name to accept" placeholder="Full name" autocomplete="name" help="Your typed name is recorded as your acceptance." />
            <button class="btn primary lg" name="decision" value="accept" type="submit">Accept quote</button>
        </form>
        <form method="POST" action="{{ $action }}" data-confirm="Decline quote {{ $quote->displayNumber() }}? You can ask us for a new one later." data-danger data-confirm-label="Decline quote">@csrf
            <button class="btn ghost" name="decision" value="decline" type="submit">Decline this quote</button>
        </form>
    </div>
</section>
