@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Knowledge base')
@push('head') @include('admin.partials.filters-style') @endpush
@section('content')
<x-page-head title="Knowledge base" sub="Help articles for your customers, organised in categories.">
    <a class="btn" href="{{ route('kb.index') }}" target="_blank" rel="noopener"><x-icon name="external-link" :size="15" /> View help center</a>
    <a class="btn" href="{{ route('admin.kb.categories.create') }}"><x-icon name="folder-plus" :size="15" /> Add category</a>
    <a class="btn primary" href="{{ route('admin.kb.articles.create') }}"><x-icon name="plus" :size="15" /> Add article</a>
</x-page-head>
@if (! \App\Services\Knowledgebase\Knowledgebase::publicAccess())<div class="notice"><div>Guests cannot read the knowledge base: only signed-in customers can. Change this in <a href="{{ route('admin.settings.index', 'knowledgebase') }}">Settings</a>.</div></div>@endif
<x-view-tabs :tabs="['all' => 'All', 'published' => 'Published', 'draft' => 'Drafts']" :current="$view" :counts="$counts" label="Article views" />
<form class="filters" method="GET" action="{{ route('admin.kb.index') }}">
    @if ($view !== 'all')<input type="hidden" name="view" value="{{ $view }}">@endif
    <div class="searchbox"><x-icon name="search" :size="15" /><input type="search" name="q" value="{{ $q }}" placeholder="Search title, summary or text…" aria-label="Search articles"></div>
    <select name="category" aria-label="Category" data-autosubmit><option value="">All categories</option>@foreach ($options as $o)<option value="{{ $o['id'] }}" @selected($category?->id === $o['id'])>{{ $o['label'] }}</option>@endforeach</select>
    <button class="btn" type="submit">Apply</button>
    @if ($q !== '' || $category)<a class="btn ghost" href="{{ route('admin.kb.index', $view === 'all' ? [] : ['view' => $view]) }}">Clear filters</a>@endif
</form>
<x-card :flush="true">
    @if ($filtering)
        @if ($articles->isEmpty())
            <x-empty icon="book-open" title="No articles found" text="Nothing matches this view. Try different words or clear the filters." />
        @else
        <table class="t stack-sm"><thead><tr><th>Article</th><th>Category</th><th>Status</th><th class="right">Views</th><th class="right">Helpful</th><th>Updated</th></tr></thead><tbody>
            @foreach ($articles as $a)
                @php($votes = $a->helpful_yes + $a->helpful_no)
                <tr class="click" onclick="location.href='{{ route('admin.kb.articles.edit', $a) }}'">
                    <td data-label="Article"><a href="{{ route('admin.kb.articles.edit', $a) }}"><b>{{ $a->title }}</b></a>@if ($a->summary)<div class="muted small">{{ \Illuminate\Support\Str::limit($a->summary, 90) }}</div>@endif</td>
                    <td data-label="Category">{{ $a->category_id ? $kb->ancestry($a->category)->pluck('name')->implode(' › ') : '—' }}</td>
                    <td data-label="Status"><span class="pill {{ $a->isPublished() ? 'good' : '' }}">{{ $a->isPublished() ? 'Published' : 'Draft' }}</span></td>
                    <td data-label="Views" class="right">{{ number_format($a->views) }}</td>
                    <td data-label="Helpful" class="right">{{ $votes ? round($a->helpful_yes / $votes * 100).'%' : '—' }}</td>
                    <td data-label="Updated" class="nowrap">{{ $a->updated_at->diffForHumans() }}</td>
                </tr>
            @endforeach
        </tbody></table>
        {{ $articles->links() }}
        @endif
    @elseif ($tree->isEmpty() && $root->isEmpty())
        <x-empty icon="book-open" title="No articles yet" text="Start with a category such as Getting started, then add your first article."><a class="btn primary sm" href="{{ route('admin.kb.categories.create') }}">Add category</a></x-empty>
    @else
    <div class="tree" role="tree">
        @foreach ($tree as $node)@include('admin.kb._node', ['node' => $node, 'depth' => 0, 'siblings' => $tree->count(), 'index' => $loop->index])@endforeach
        @if ($root->isNotEmpty())
            <div class="tree-row tree-group"><span class="tree-name"><x-icon name="file-text" :size="15" /> <b>Not in a category</b></span></div>
            @foreach ($root as $a)@include('admin.kb._article', ['a' => $a, 'depth' => 1, 'siblings' => $root->count(), 'index' => $loop->index])@endforeach
        @endif
    </div>
    @endif
</x-card>
@endsection
