@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Confirm order')
@section('content')
<x-page-head title="Confirm your order" :back="route('store.show', $product)" back-label="Back" />
<x-card title="{{ $product->name }}">
    <dl class="kv wide">
        <dt>Billing cycle</dt><dd>{{ $cycle->label() }}</dd>
        @if ($label)<dt>{{ $product->label_prompt ?: 'Reference' }}</dt><dd>{{ $label }}</dd>@endif
        <dt>Price</dt><dd>{{ money($price->amount, $price->currency) }} {{ $cycle->suffix() }}</dd>
        @if ($price->setup_fee)<dt>Setup fee</dt><dd>{{ money($price->setup_fee, $price->currency) }}</dd>@endif
        @if ($tax)<dt>{{ $rate->name }} ({{ rtrim(rtrim(number_format($rate->rate, 3), '0'), '.') }}%)</dt><dd>{{ money($tax, $price->currency) }}</dd>@endif
        <dt>Due today</dt><dd><b>{{ money($total, $price->currency) }}</b></dd>
    </dl>
    <form method="POST" action="{{ route('portal.order.store') }}" class="row" style="margin-top:16px">@csrf
        <input type="hidden" name="product" value="{{ $product->slug }}"><input type="hidden" name="cycle" value="{{ $cycle->value }}"><input type="hidden" name="label" value="{{ $label }}">
        <button class="btn primary" type="submit">Place order</button><a class="btn" href="{{ route('portal.store') }}">Cancel</a>
    </form>
</x-card>
@endsection
