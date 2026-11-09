@props(['name', 'size' => 16])
<svg class="icon" width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" aria-hidden="true" {{ $attributes }}><use href="{{ asset('assets/icons.svg') }}?v={{ @filemtime(public_path('assets/icons.svg')) }}#{{ $name }}"/></svg>
