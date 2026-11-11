@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Review your order')
@section('content')
@php
    $cur = $price->currency;
    $editUrl = route('store.show', ['product' => $product->slug, 'cycle' => $cycle->value, 'label' => $label ?: null, 'addons' => $addonRows->pluck('product.slug')->all() ?: null]);
    $needsMethod = $trialAvailable && $product->trial_requires_payment_method;
    $trialEnds = $trialAvailable ? now()->addDays($product->trial_days) : null;
    $blocked = $needsMethod && ! $savedMethod;
@endphp
<x-page-head title="Review your order" sub="Check the details. Nothing is charged until you place the order." :back="$editUrl" back-label="Edit plan" />
<x-steps :items="['Choose plan', 'Account', 'Review', $trialAvailable ? 'Start trial' : 'Pay']" :current="3" />
@if (session('needs_payment_method') || $blocked)
<div class="notice warn"><x-icon name="triangle-alert" :size="16" /><div>This free trial needs a saved payment method, so we can bill you when it ends. <a class="link-underline" href="{{ route('portal.payment-methods.index') }}">Add a payment method</a>, then come back to place your order.</div></div>
@endif
<div class="summary-grid">
    <div class="stack">
        <section class="card setting">
            <div class="setting-h"><h2>{{ $product->name }}</h2><p>{{ $cycle->label() }} billing{{ $label ? ' · '.($product->label_prompt ?: 'Reference').': '.$label : '' }}</p></div>
            <div class="setting-b stack sm">
                <x-checklist :text="$product->description" :limit="6" />
                @if ($trialAvailable)<p class="m-0"><span class="pill good">{{ $product->trial_days }}-day free trial</span></p>@endif
            </div>
            <div class="setting-f"><span class="help">Wrong plan, cycle or {{ strtolower($product->label_prompt ?: 'reference') }}?</span><a class="btn sm" href="{{ $editUrl }}">Edit</a></div>
        </section>
        @if ($needsMethod && $savedMethod)
        <section class="card setting">
            <div class="setting-h"><h2>Payment method</h2><p>Used to bill you when the trial ends.</p></div>
            <div class="setting-b"><b>{{ $savedMethod->displayName() }}</b>@if ($savedMethod->expiry()) <span class="muted small">Expires {{ $savedMethod->expiry() }}</span>@endif</div>
            <div class="setting-f"><span class="help">You can change it before the trial ends.</span><a class="btn sm" href="{{ route('portal.payment-methods.index') }}">Manage</a></div>
        </section>
        @endif
    </div>
    <section class="card summary-card" aria-label="Order summary">
        <h2>Summary</h2>
        <dl class="summary-lines num">
            <div><dt>{{ $product->name }} ({{ strtolower($cycle->label()) }})</dt><dd>{{ money($price->amount, $cur) }}</dd></div>
            @foreach ($addonRows as $row)<div><dt>{{ $row['product']->name }} (add-on)</dt><dd>{{ money($row['amount'], $cur) }}</dd></div>@endforeach
            @if ($price->setup_fee)<div><dt>Setup fee (one time)</dt><dd>{{ money($price->setup_fee, $cur) }}</dd></div>@endif
            @if ($tax)<div><dt>{{ $rate->name }} ({{ rtrim(rtrim(number_format($rate->rate, 3), '0'), '.') }}%)</dt><dd>{{ money($tax, $cur) }}</dd></div>@endif
            @if ($trialAvailable)
                <div><dt>Total after the trial</dt><dd>{{ money($total, $cur) }}</dd></div>
                <div class="total"><dt>Due today</dt><dd>{{ money(0, $cur) }}</dd></div>
            @else
                <div class="total"><dt>Due today</dt><dd>{{ money($total, $cur) }}</dd></div>
            @endif
        </dl>
        <div class="summary-next stack sm">
            <b>What happens next</b>
            <ul class="checklist">
                @if ($trialAvailable)
                    <li><x-icon name="check" :size="16" /><span>Your trial starts now and your service is set up straight away.</span></li>
                    <li><x-icon name="check" :size="16" /><span>On {{ $trialEnds->format('j M Y') }} we send your first invoice for {{ money($total, $cur) }}.</span></li>
                    @if ($addonRows->isNotEmpty())<li><x-icon name="check" :size="16" /><span>Add-ons are free until then.</span></li>@endif
                    <li><x-icon name="check" :size="16" /><span>Cancel before then and you pay nothing.</span></li>
                @else
                    <li><x-icon name="check" :size="16" /><span>We create an invoice for {{ money($total, $cur) }} and take you to pay it.</span></li>
                    <li><x-icon name="check" :size="16" /><span>Your service is set up as soon as the payment arrives.</span></li>
                @endif
                @if ($cycle->suffix())<li><x-icon name="check" :size="16" /><span>Renews at {{ money($price->amount + $addonRows->sum('amount'), $cur) }} {{ $cycle->suffix() }} until you cancel.</span></li>@endif
            </ul>
        </div>
        <form method="POST" action="{{ route('portal.order.store') }}" class="stack sm">@csrf
            <input type="hidden" name="product" value="{{ $product->slug }}"><input type="hidden" name="cycle" value="{{ $cycle->value }}"><input type="hidden" name="label" value="{{ $label }}">@foreach ($addonRows as $row)<input type="hidden" name="addons[]" value="{{ $row['product']->slug }}">@endforeach
            @if ($termsUrl)<p class="help m-0">By ordering you agree to the <a class="link-underline" href="{{ $termsUrl }}" rel="noopener" target="_blank">terms of service</a>.</p>@endif
            <button class="btn primary lg" type="submit" @disabled($blocked)>{{ $trialAvailable ? 'Start free trial' : 'Place order, pay '.money($total, $cur) }}</button>
            <a class="btn ghost" href="{{ route('portal.store') }}">Cancel</a>
        </form>
    </section>
</div>
@endsection
