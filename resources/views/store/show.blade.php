@extends(auth()->check() ? 'layouts.app' : 'layouts.public', ['area' => 'portal']) {{-- signed in: the client-area shell; guests: the plain public one --}}
@section('title', $product->name)
@section('content')
<div class="store">
    <x-page-head :title="$product->name" :back="route('store.index')" back-label="All services" />
    <x-steps :items="['Choose plan', 'Review', $trial ? 'Start trial' : 'Pay']" :current="1" />
    <div class="product-layout">
        <div class="stack product-info">
            @if ($trial)<div class="notice good"><x-icon name="circle-check" :size="16" /><div><b>{{ $product->trial_days }}-day free trial.</b> You pay nothing today, and you can cancel before the trial ends to avoid any charge.</div></div>
            @elseif ($trialUsed)<div class="notice"><x-icon name="info" :size="16" /><div>You have already used the free trial for this plan, so it is billed from the start.</div></div>@endif
            <section class="card setting">
                <div class="setting-h"><h2>What is included</h2></div>
                <div class="setting-b"><x-checklist :text="$product->description" class="checklist-lg" />@if (! trim((string) $product->description))<p class="muted">Ask us if you need details about this plan.</p>@endif
                    @if ($metered)<p class="help mt-16">Usage is billed after each period, on top of the price shown.</p>@endif</div>
            </section>
        </div>
        <form method="GET" action="{{ route('portal.order.create') }}" class="card order-card stack" aria-label="Order {{ $product->name }}">
            <input type="hidden" name="product" value="{{ $product->slug }}">
            <fieldset class="choice-group"><legend>Billing cycle</legend>
                @foreach ($prices as $pr)
                    @php($d = $due[$pr->cycle->value])
                    <label class="choice"><input type="radio" name="cycle" value="{{ $pr->cycle->value }}" @checked($pr->cycle === $selected->cycle)>
                        <span class="choice-body">
                            <span class="choice-name">{{ $pr->cycle->label() }}@if (isset($savings[$pr->cycle->value])) <span class="pill good">Save {{ $savings[$pr->cycle->value] }}%</span>@endif</span>
                            <span class="choice-due muted small">Due today {{ money($d['today'], $currency) }}@if ($trial && $pr->cycle->isRecurring()) (free trial)@else @if ($pr->setup_fee) incl. {{ money($pr->setup_fee, $currency) }} setup @endif @if ($d['tax']) + {{ money($d['tax'], $currency) }} {{ $rate->name }}@elseif (! $known) · tax added at checkout @endif @endif</span>
                        </span>
                        <span class="choice-price num"><b>{{ money($pr->amount, $currency) }}</b>@if ($pr->cycle->suffix()) <span class="muted small">{{ $pr->cycle->suffix() }}</span>@endif</span></label>
                @endforeach
            </fieldset>
            @if ($product->label_prompt)<x-field name="label" :label="$product->label_prompt" :value="$label" required />@endif
            <div class="order-cta stack sm">
                <button class="btn primary lg" type="submit">Continue to review</button>
                @guest<p class="help m-0">You will sign in or create an account at the next step. Nothing is billed yet.</p>
                @else<p class="help m-0">You can review everything before you order.</p>@endguest
            </div>
        </form>
    </div>
</div>
@endsection
