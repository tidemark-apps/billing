@extends('layouts.public', ['area' => 'portal'])
@section('title', 'Create your account')
@section('content')
@php
    $cur = $currency;
    $editUrl = route('store.show', ['product' => $product->slug, 'cycle' => $cycle->value, 'label' => $label ?: null, 'addons' => $addons->pluck('product.slug')->all() ?: null]);
    $recurring = $price->amount + $addons->sum(fn ($a) => $a['price']->amount);
@endphp
<div class="page-head"><div>
    <div class="crumb"><a href="{{ $editUrl }}"><x-icon name="chevron-left" :size="14" /> Edit plan</a></div>
    <h1>Create your account</h1>
    <p class="sub">Takes a minute. You review the order before anything is charged.</p>
</div>
<div class="co-login"><span>Already have an account?</span><a class="btn sm" href="{{ route('login') }}">Log in</a></div></div>
<x-steps :items="['Choose plan', 'Account', 'Review', $trial ? 'Start trial' : 'Pay']" :current="2" />
<div class="summary-grid">
    <form method="POST" action="{{ route('checkout.account.store') }}" class="card co-form stack" novalidate>
        @csrf
        <input type="hidden" name="product" value="{{ $product->slug }}"><input type="hidden" name="cycle" value="{{ $cycle->value }}">
        @if ($label)<input type="hidden" name="label" value="{{ $label }}">@endif
        @foreach ($addons as $a)<input type="hidden" name="addons[]" value="{{ $a['product']->slug }}">@endforeach
        <fieldset class="co-group">
            <legend>About you</legend>
            <div class="grid2">
                <x-field name="name" label="Your name" required autofocus autocomplete="name" />
                <x-field name="company_name" label="Company (optional)" autocomplete="organization" />
            </div>
            <div class="grid2">
                <x-field name="email" label="Email" type="email" required autocomplete="email" autocapitalize="none" spellcheck="false" />
                <x-field name="phone" label="Phone (optional)" type="tel" autocomplete="tel" />
            </div>
        </fieldset>
        <fieldset class="co-group">
            <legend>Billing address</legend>
            <p class="help m-0">Used on your invoices and to work out any tax.</p>
            <x-field name="country" label="Country" type="select" required autocomplete="country" :options="$countries" />
            <x-field name="address_1" label="Address" required autocomplete="address-line1" />
            <x-field name="address_2" label="Apartment, suite, etc. (optional)" autocomplete="address-line2" />
            <div class="grid2">
                <x-field name="city" label="City" required autocomplete="address-level2" />
                <x-field name="state" label="State / region (optional)" autocomplete="address-level1" />
            </div>
            <div class="grid2">
                <x-field name="postcode" label="Postcode" required autocomplete="postal-code" />
                <x-field name="tax_id" label="VAT / tax ID (optional)" />
            </div>
        </fieldset>
        <fieldset class="co-group">
            <legend>Choose a password</legend>
            <x-portal-password name="password" label="Password" autocomplete="new-password" help="At least 8 characters." />
        </fieldset>
        <div class="stack sm">
            @if ($termsUrl)<p class="help m-0">By creating an account you agree to the <a class="link-underline" href="{{ $termsUrl }}" rel="noopener" target="_blank">terms of service</a>.</p>@endif
            <button class="btn primary lg" type="submit">Continue to review</button>
            <p class="help m-0">Nothing is charged yet. You review the order on the next step.</p>
        </div>
    </form>
    <section class="card summary-card co-summary" aria-label="Order summary">
        <h2>Your order</h2>
        <dl class="summary-lines num">
            <div><dt>{{ $product->name }} ({{ strtolower($cycle->label()) }})</dt><dd>{{ money($price->amount, $cur) }}</dd></div>
            @foreach ($addons as $a)<div><dt>{{ $a['product']->name }} (add-on)</dt><dd>{{ money($a['price']->amount, $cur) }}</dd></div>@endforeach
            @if ($price->setup_fee)<div><dt>Setup fee (one time)</dt><dd>{{ money($price->setup_fee, $cur) }}</dd></div>@endif
        </dl>
        @if ($trial)<p class="m-0"><span class="pill good">{{ $product->trial_days }}-day free trial</span></p>@endif
        <p class="help m-0">Any tax is added on the review step, once we have your address.@if ($cycle->suffix()) Renews at {{ money($recurring, $cur) }} {{ $cycle->suffix() }}.@endif</p>
        <x-checklist :text="$product->description" :limit="5" />
    </section>
</div>
@endsection
