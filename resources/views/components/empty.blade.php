@props(['icon' => 'layers', 'title', 'text' => null])
<div class="empty">
    <div class="ico"><x-icon :name="$icon" :size="20" /></div>
    <b>{{ $title }}</b>
    @if ($text)<p>{{ $text }}</p>@endif
    @if (! $slot->isEmpty())<div class="row" style="margin-top:8px">{{ $slot }}</div>@endif
</div>
