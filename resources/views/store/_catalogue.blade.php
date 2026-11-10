@include('store._header', ['groups' => $groups, 'public' => $public])
@foreach ($groups as $g)
    <section class="stack mt-24" id="group-{{ $g['group']->slug }}" aria-labelledby="h-{{ $g['group']->slug }}">
        <div><h2 id="h-{{ $g['group']->slug }}">{{ $g['group']->name }}</h2>@if ($g['group']->description)<p class="muted">{{ $g['group']->description }}</p>@endif</div>
        <div class="product-grid">@foreach ($g['products'] as $p)@include('store._card', ['p' => $p])@endforeach</div>
    </section>
@endforeach
@if ($ungrouped->isNotEmpty())
    <section class="stack mt-24"><div class="product-grid">@foreach ($ungrouped as $p)@include('store._card', ['p' => $p])@endforeach</div></section>
@endif
@if ($groups->isEmpty() && $ungrouped->isEmpty())<x-empty icon="package" title="Nothing for sale yet" text="No plans are available to order right now. Check back soon, or contact us if you need something specific.">@if (! $public)<a class="btn" href="{{ route('portal.dashboard') }}">Back to overview</a>@endif</x-empty>@endif
