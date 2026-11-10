@props(['groups' => collect(), 'public' => true])
@php($company = \App\Support\Settings::get('company.name') ?: config('billing.name'))
<header class="store-head">
    <h1>{{ $public ? 'Services from '.$company : 'Order services' }}</h1>
    <p class="muted">{{ $public ? 'Pick a plan and order online in a few minutes. You can cancel any time from your client area.' : 'Choose a plan to add to your account. You will review the price before anything is billed.' }}</p>
    @if ($groups->count() > 1)
    <nav class="tabs" aria-label="Product groups">@foreach ($groups as $g)<a href="#group-{{ $g['group']->slug }}">{{ $g['group']->name }}<span class="tab-count">{{ $g['products']->count() }}</span></a>@endforeach</nav>
    @endif
</header>
