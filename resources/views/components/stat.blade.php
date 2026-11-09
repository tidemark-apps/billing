@props(['label', 'value', 'hint' => null, 'icon' => null])
<div class="card stat">
    <div class="k"><span>{{ $label }}</span>@if($icon)<x-icon :name="$icon" :size="15" />@endif</div>
    <div class="v">{{ $value }}</div>
    @if ($hint)<div class="prev">{{ $hint }}</div>@endif
</div>
