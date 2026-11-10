@php($c = $node['category'])
<li>
    <details @if (in_array($c->id, $openIds, true)) open @endif>
        <summary><span>{{ $c->name }}</span><x-icon name="chevron-right" :size="14" /></summary>
        <ul>
            <li><a href="{{ $kb->categoryUrl($c) }}" class="{{ ($category->id ?? null) === $c->id ? 'on' : '' }}">Overview</a></li>
            @foreach ($node['children'] as $child)@include('kb._nav-node', ['node' => $child])@endforeach
            @foreach ($node['articles'] as $a)<li><a href="{{ $kb->articleUrl($a) }}" class="{{ $currentArticle === $a->id ? 'on' : '' }}">{{ $a->title }}@unless ($a->isPublished()) <span class="pill">Draft</span>@endunless</a></li>@endforeach
        </ul>
    </details>
</li>
