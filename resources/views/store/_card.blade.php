<div class="card product-card">
    <h3 style="font-size:16px">{{ $p->name }}</h3>
    <p class="muted small">{{ \Illuminate\Support\Str::limit(strip_tags((string) $p->description), 140) }}</p>
    <div class="price"><small>From</small> {{ money($p->from_price->amount, $p->from_currency) }} <small>{{ $p->from_price->cycle->suffix() }}</small></div>
    <a class="btn primary" href="{{ route('store.show', $p) }}" style="margin-top:auto">View &amp; order</a>
</div>
