@props(['status'])
@php($s = $status instanceof \BackedEnum ? $status : null)
<span class="pill {{ $s && method_exists($s, 'tone') ? $s->tone() : '' }}">{{ $s && method_exists($s, 'label') ? $s->label() : ucfirst((string) $status) }}</span>
