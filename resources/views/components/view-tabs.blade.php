@props(['tabs', 'current', 'counts' => [], 'param' => 'view', 'label' => 'Views'])
{{-- Saved-view tabs with live counts (Linear/Stripe pattern). Keeps the other query params, resets pagination. --}}
<nav class="tabs page-tabs" aria-label="{{ $label }}">
    @foreach ($tabs as $key => $text)
        @php($url = request()->fullUrlWithQuery([$param => $key === array_key_first($tabs) ? null : $key, 'page' => null]))
        <a href="{{ $url }}" class="{{ $current === $key ? 'on' : '' }}" @if ($current === $key) aria-current="page" @endif>{{ $text }}@if (array_key_exists($key, $counts))<span class="tab-count">{{ number_format($counts[$key]) }}</span>@endif</a>
    @endforeach
</nav>
