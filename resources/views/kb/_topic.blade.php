@php($c = $node['category'])
<a class="topic" href="{{ $kb->categoryUrl($c) }}">
    <span class="topic-icon"><x-icon :name="$c->icon ?: 'folder'" :size="18" /></span>
    <b>{{ $c->name }}</b>
    @if ($c->description)<span class="muted">{{ $c->description }}</span>@endif
    <span class="muted small">{{ $node['count'] }} {{ \Illuminate\Support\Str::plural('article', $node['count']) }}</span>
</a>
