@php($c = $node['category'])
<div class="tree-row tree-group" style="--depth: {{ $depth }}" role="treeitem" aria-expanded="true">
    <span class="tree-name"><x-icon :name="$c->icon ?: 'folder'" :size="15" /> <a href="{{ route('admin.kb.categories.edit', $c) }}"><b>{{ $c->name }}</b></a>@unless ($c->is_visible)<span class="pill">Hidden</span>@endunless <span class="muted small">{{ $node['count'] }} {{ \Illuminate\Support\Str::plural('article', $node['count']) }}</span></span>
    <span class="tree-actions">
        <form method="POST" action="{{ route('admin.kb.categories.move', $c) }}" class="row">@csrf
            <button class="btn sm ghost icon" name="direction" value="up" aria-label="Move {{ $c->name }} up" @disabled($index === 0)><x-icon name="chevron-up" :size="14" /></button>
            <button class="btn sm ghost icon" name="direction" value="down" aria-label="Move {{ $c->name }} down" @disabled($index === $siblings - 1)><x-icon name="chevron-down" :size="14" /></button>
        </form>
        <x-menu label="Add"><a class="item" href="{{ route('admin.kb.articles.create', ['category' => $c->id]) }}">Article here</a><a class="item" href="{{ route('admin.kb.categories.create', ['parent' => $c->id]) }}">Subcategory</a></x-menu>
    </span>
</div>
@foreach ($node['children'] as $child)@include('admin.kb._node', ['node' => $child, 'depth' => $depth + 1, 'siblings' => $node['children']->count(), 'index' => $loop->index])@endforeach
@foreach ($node['articles'] as $a)@include('admin.kb._article', ['a' => $a, 'depth' => $depth + 1, 'siblings' => $node['articles']->count(), 'index' => $loop->index])@endforeach
