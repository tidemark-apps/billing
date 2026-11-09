@props(['title', 'sub' => null, 'back' => null, 'backLabel' => 'Back'])
<div class="page-head">
    <div>
        @if ($back)<div class="crumb"><a href="{{ $back }}"><x-icon name="chevron-left" :size="14" /> {{ $backLabel }}</a></div>@endif
        <h1>{{ $title }}</h1>
        @if ($sub)<p class="sub">{{ $sub }}</p>@endif
    </div>
    @if (! $slot->isEmpty())<div class="row wrap">{{ $slot }}</div>@endif
</div>
