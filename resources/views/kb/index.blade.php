@extends(auth()->check() ? 'layouts.app' : 'layouts.public', ['area' => 'portal']) {{-- signed in: the client-area shell; guests: the plain public one --}}
@section('title', \App\Services\Knowledgebase\Knowledgebase::title())
@section('content')
<header class="docs-hero">
    <h1>{{ \App\Services\Knowledgebase\Knowledgebase::title() }}</h1>
    @if ($intro = trim((string) \App\Support\Settings::get('kb.intro', 'Guides and answers to common questions.')))<p class="muted">{{ $intro }}</p>@endif
    @include('kb._search', ['large' => true])
</header>
@if ($tree->isEmpty() && $root->isEmpty())
    <x-empty icon="book-open" title="Nothing here yet" text="Articles will show up here once they are published." />
@else
    @if ($tree->isNotEmpty())<div class="topic-grid">@foreach ($tree as $node)@include('kb._topic', ['node' => $node])@endforeach</div>@endif
    @if ($root->isNotEmpty())
        <section class="docs-block"><h2>Articles</h2><ul class="article-list">@foreach ($root as $a)@include('kb._article-row', ['a' => $a])@endforeach</ul></section>
    @endif
    <div class="grid2 docs-block">
        @if ($popular->isNotEmpty())<section><h2>Most read</h2><ul class="article-list">@foreach ($popular as $a)@include('kb._article-row', ['a' => $a, 'compact' => true])@endforeach</ul></section>@endif
        @if ($recent->isNotEmpty())<section><h2>Recently updated</h2><ul class="article-list">@foreach ($recent as $a)@include('kb._article-row', ['a' => $a, 'compact' => true])@endforeach</ul></section>@endif
    </div>
@endif
@endsection
