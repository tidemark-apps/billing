@php($mkUrl = fn ($p) => route('store.show', $p))
@forelse ($groups as $g)
    <section class="stack sm" style="margin-top:24px">
        <div><h2>{{ $g['group']->name }}</h2>@if ($g['group']->description)<p class="muted">{{ $g['group']->description }}</p>@endif</div>
        <div class="product-grid">@foreach ($g['products'] as $p)@include('store._card', ['p' => $p])@endforeach</div>
    </section>
@empty
@endforelse
@if ($ungrouped->isNotEmpty())
    <section class="stack sm" style="margin-top:24px"><div class="product-grid">@foreach ($ungrouped as $p)@include('store._card', ['p' => $p])@endforeach</div></section>
@endif
@if ($groups->isEmpty() && $ungrouped->isEmpty())<x-empty icon="package" title="Nothing for sale yet" text="Check back soon." />@endif
