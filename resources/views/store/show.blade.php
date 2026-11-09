@extends('layouts.public')
@section('title', $product->name)
@section('content')
<div class="narrow-doc stack">
    <x-page-head :title="$product->name" :back="route('store.index')" back-label="All services" />
    <div class="card pad stack">
        @if ($product->description)<p style="white-space:pre-line">{{ $product->description }}</p>@endif
        <form method="GET" action="{{ route('portal.order.create') }}" class="stack">
            <input type="hidden" name="product" value="{{ $product->slug }}">
            <fieldset style="border:0;padding:0;margin:0" class="stack sm"><legend class="muted small" style="padding:0;margin-bottom:6px">Billing cycle</legend>
                @foreach ($prices as $pr)
                    <label class="row" style="cursor:pointer"><input type="radio" name="cycle" value="{{ $pr->cycle->value }}" @checked($loop->first)>
                        <span><b>{{ money($pr->amount, $currency) }}</b> {{ $pr->cycle->suffix() ?: '' }} <span class="muted">· {{ $pr->cycle->label() }}</span>@if($pr->setup_fee) <span class="muted small">+ {{ money($pr->setup_fee, $currency) }} setup</span>@endif</span></label>
                @endforeach
            </fieldset>
            @if ($product->label_prompt)<x-field name="label" :label="$product->label_prompt" required />@endif
            <div><button class="btn primary lg" type="submit">Order now</button></div>
            @guest<p class="help">You'll be asked to sign in or create an account to finish.</p>@endguest
        </form>
    </div>
</div>
@endsection
