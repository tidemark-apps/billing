<article class="card product-card">
    <div class="row spread">
        <h3>{{ $p->name }}</h3>
        @if ($p->trial_days)<span class="pill good">{{ $p->trial_days }}-day free trial</span>@endif
    </div>
    <div class="price num">
        <div>@if ($p->meters_count)<small>From</small> @endif<span class="price-amt">{{ money($p->from_price->amount, $p->from_currency) }}</span>@if ($p->from_price->cycle->suffix()) <small>{{ $p->from_price->cycle->suffix() }}</small>@endif</div>
        <div class="price-note muted small">
            @if ($p->from_price->setup_fee){{ money($p->from_price->setup_fee, $p->from_currency) }} setup fee @endif
            @if ($p->best_saving)@if ($p->from_price->setup_fee) · @endif Save up to {{ $p->best_saving }}% on longer billing cycles @endif
            @if ($p->meters_count)@if ($p->from_price->setup_fee || $p->best_saving) · @endif plus usage @endif
        </div>
    </div>
    <x-checklist :text="$p->description" :limit="5" />
    <a class="btn primary mt-auto" href="{{ route('store.show', $p) }}">Choose {{ $p->name }}</a>
</article>
