@props(['items', 'current' => 1])
<ol class="steps" aria-label="Order progress">
    @foreach ($items as $i => $text)
        @php($n = $i + 1)
        <li @class(['done' => $n < $current, 'on' => $n === $current]) @if ($n === $current) aria-current="step" @endif><span class="steps-n">@if ($n < $current)<x-icon name="check" :size="14" />@else{{ $n }}@endif</span><span class="steps-t">{{ $text }}</span></li>
    @endforeach
</ol>
