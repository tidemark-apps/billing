{{-- Side menu: nested categories (open along the current path) with their articles. Expects $tree, $kb; optional $openIds, $currentArticle. --}}
@php
    $root = $kb->rootArticles((bool) auth()->user()?->isStaff());
    $openIds = $openIds ?? [];
    $currentArticle = $currentArticle ?? null;
@endphp
<nav class="docs-nav" aria-label="Knowledge base">
    @include('kb._search')
    <a class="docs-browse" href="{{ route('kb.index') }}">Browse all topics</a>
    <ul class="docs-tree">
        <li><a href="{{ route('kb.index') }}" class="{{ request()->routeIs('kb.index') ? 'on' : '' }}">{{ \App\Services\Knowledgebase\Knowledgebase::title() }}</a></li>
        @foreach ($tree as $node)@include('kb._nav-node', ['node' => $node])@endforeach
        @foreach ($root as $a)<li><a href="{{ $kb->articleUrl($a) }}" class="{{ $currentArticle === $a->id ? 'on' : '' }}">{{ $a->title }}</a></li>@endforeach
    </ul>
</nav>
