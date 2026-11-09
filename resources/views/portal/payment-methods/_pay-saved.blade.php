@php
    $__user = auth()->user();
    $__saved = ($__user && $__user->client_id && $__user->client_id === $invoice->client_id && $invoice->isPayable())
        ? app(\App\Services\PaymentMethods::class)->usableFor($invoice)
        : collect();
@endphp
@if ($__saved->isNotEmpty())
<form method="POST" action="{{ route('portal.payment-methods.pay', $invoice) }}" class="row wrap">@csrf
    <select name="method" aria-label="Saved payment method">@foreach ($__saved as $m)<option value="{{ $m->uuid }}">{{ $m->displayName() }}</option>@endforeach</select>
    <button class="btn" type="submit"><x-icon name="credit-card" :size="15" /> Pay with saved method</button>
</form>
@endif
