@props(['text' => '', 'limit' => null])
{{-- Free-text product description: two or more lines become a tick list, a single line stays a paragraph. --}}
@php
    $lines = collect(preg_split('/\R/', strip_tags((string) $text)))->map(fn ($l) => trim(ltrim(trim($l), "-*•\t ")))->filter()->values();
    $shown = $limit ? $lines->take($limit) : $lines;
@endphp
@if ($lines->count() >= 2)
<ul {{ $attributes->class(['checklist']) }}>
    @foreach ($shown as $line)<li><x-icon name="check" :size="16" /><span>{{ $line }}</span></li>@endforeach
    @if ($shown->count() < $lines->count())<li class="more"><span>and {{ $lines->count() - $shown->count() }} more</span></li>@endif
</ul>
@elseif ($lines->count() === 1)<p {{ $attributes->class(['muted']) }}>{{ $lines->first() }}</p>@endif
