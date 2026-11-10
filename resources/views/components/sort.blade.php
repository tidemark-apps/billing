@props(['key', 'sort', 'dir', 'right' => false, 'default' => 'asc'])
{{-- Sortable column header: one arrow on the active column, URL-driven (sort, dir), resets pagination. --}}
@php
    $active = $sort === $key;
    $next = $active ? ($dir === 'asc' ? 'desc' : 'asc') : $default;
    $url = request()->fullUrlWithQuery(['sort' => $key, 'dir' => $next, 'page' => null]);
@endphp
<th class="{{ $right ? 'right' : '' }}" @if ($active) aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
    <a class="th-sort" href="{{ $url }}" title="Sort by {{ strtolower(strip_tags((string) $slot)) }}">{{ $slot }}@if ($active)<x-icon :name="$dir === 'asc' ? 'arrow-up' : 'arrow-down'" :size="12" />@endif</a>
</th>
