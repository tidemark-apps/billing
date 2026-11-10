@extends(auth()->check() ? 'layouts.app' : 'layouts.public', ['area' => 'portal']) {{-- signed in: the client-area shell; guests: the plain public one --}}
@section('title', $category->name.' · '.\App\Services\Knowledgebase\Knowledgebase::title())
@section('content')
<div class="docs">
    @include('kb._nav', ['root' => collect(), 'openIds' => $crumbs->pluck('id')->all(), 'currentCategory' => $category->id])
    <div class="docs-main">
        @include('kb._crumbs')
        <header class="docs-title spread"><div><h1>{{ $category->name }}@unless ($category->is_visible) <span class="pill">Hidden</span>@endunless</h1>@if ($category->description)<p class="muted">{{ $category->description }}</p>@endif</div>@if ($staff)<div class="row"><a class="btn sm" href="{{ route('admin.kb.categories.edit', $category) }}">Edit category</a><a class="btn sm primary" href="{{ route('admin.kb.articles.create', ['category' => $category->id]) }}">Add article</a></div>@endif</header>
        @if ($children->isNotEmpty())<div class="topic-grid">@foreach ($children as $node)@include('kb._topic', ['node' => $node])@endforeach</div>@endif
        @if ($articles->isNotEmpty())<ul class="article-list">@foreach ($articles as $a)@include('kb._article-row', ['a' => $a])@endforeach</ul>
        @elseif ($children->isEmpty())<x-empty icon="file-text" title="No articles here yet" text="Check back soon." />@endif
    </div>
</div>
@endsection
