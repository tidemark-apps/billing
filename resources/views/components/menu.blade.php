@props(['label' => 'More', 'icon' => 'ellipsis'])
{{-- Overflow menu on the shared popover behaviour. Put .item links, and forms holding a button.item, inside. Destructive items get class "item danger". --}}
<div class="pop-wrap">
    <button class="btn" type="button" data-pop-trigger aria-haspopup="menu" aria-expanded="false"><x-icon :name="$icon" :size="15" /> {{ $label }}</button>
    <div class="pop hidden" role="menu">{{ $slot }}</div>
</div>
