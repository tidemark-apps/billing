@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Confirm order')
@section('content')
<x-page-head title="Confirm your order" :back="route('store.show', $product)" back-label="Back" />
@if (session('needs_payment_method'))
<div class="notice warn"><x-icon name="triangle-alert" :size="16" /><div>This trial needs a saved payment method. <a href="{{ route('portal.payment-methods.index') }}" style="text-decoration:underline">Add a payment method</a>, then come back to place your order.</div></div>
@endif
<x-card title="{{ $product->name }}">
    <dl class="kv wide">
        <dt>Billing cycle</dt><dd>{{ $cycle->label() }}</dd>
        @if ($label)<dt>{{ $product->label_prompt ?: 'Reference' }}</dt><dd>{{ $label }}</dd>@endif
        <dt>Price</dt><dd>{{ money($price->amount, $price->currency) }} {{ $cycle->suffix() }}</dd>
        @if ($price->setup_fee)<dt>Setup fee</dt><dd>{{ money($price->setup_fee, $price->currency) }}</dd>@endif
        @if ($tax)<dt>{{ $rate->name }} ({{ rtrim(rtrim(number_format($rate->rate, 3), '0'), '.') }}%)</dt><dd>{{ money($tax, $price->currency) }}</dd>@endif
        @if ($trialAvailable)
        <dt>Free trial</dt><dd><span class="pill good">{{ $product->trial_days }}-day free trial</span></dd>
        <dt>Due today</dt><dd><b>{{ money(0, $price->currency) }}</b> <span class="muted small">First invoice of {{ money($total, $price->currency) }} on {{ now()->addDays($product->trial_days)->format('j M Y') }}. Cancel any time before then.</span></dd>
        @else
        <dt>Due today</dt><dd><b>{{ money($total, $price->currency) }}</b></dd>
        @endif
    </dl>
    <form method="POST" action="{{ route('portal.order.store') }}" class="row" style="margin-top:16px">@csrf
        <input type="hidden" name="product" value="{{ $product->slug }}"><input type="hidden" name="cycle" value="{{ $cycle->value }}"><input type="hidden" name="label" value="{{ $label }}">
        <button class="btn primary" type="submit">{{ $trialAvailable ? 'Start free trial' : 'Place order' }}</button><a class="btn" href="{{ route('portal.store') }}">Cancel</a>
    </form>
</x-card>
@endsection
