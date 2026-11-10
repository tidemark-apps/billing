@extends(auth()->check() ? 'layouts.app' : 'layouts.public', ['area' => 'portal']) {{-- signed in: the client-area shell; guests: the plain public one --}}
@section('title', $article->title.' · '.\App\Services\Knowledgebase\Knowledgebase::title())
@push('head')<meta name="description" content="{{ \Illuminate\Support\Str::limit($article->summary ?: strip_tags($html), 160) }}">@endpush
@section('content')
<div class="docs">
    @include('kb._nav', ['root' => collect(), 'openIds' => $crumbs->pluck('id')->all(), 'currentArticle' => $article->id])
    <div class="docs-main">
        @include('kb._crumbs', ['lastLink' => true])
        @unless ($article->isPublished())<div class="notice"><div>This article is a draft: only staff can see it. <a href="{{ route('admin.kb.articles.edit', $article) }}">Edit it</a>.</div></div>@endunless
        <article class="prose" data-prose data-hljs="{{ asset('assets/vendor/highlight/highlight.min.js') }}" data-hljs-css="{{ asset('assets/vendor/highlight/github.min.css') }}" data-hljs-css-dark="{{ asset('assets/vendor/highlight/github-dark.min.css') }}">
            <header class="docs-title"><h1>{{ $article->title }}</h1>@if ($article->summary)<p class="muted">{{ $article->summary }}</p>@endif</header>
            {!! $html !!}
        </article>
        <footer class="docs-foot">
            <div class="helpful" aria-live="polite">
                @if ($voted)<span class="muted">Thanks for the feedback.</span>@else
                <form method="POST" action="{{ route('kb.feedback', $article) }}" class="row">@csrf <span class="muted">Was this article helpful?</span>
                    <button class="btn sm" name="helpful" value="yes"><x-icon name="thumbs-up" :size="14" /> Yes</button>
                    <button class="btn sm" name="helpful" value="no"><x-icon name="thumbs-down" :size="14" /> No</button>
                </form>@endif
            </div>
            <div class="muted small">Last updated {{ $article->updated_at->format('j M Y') }}@if ($staff) · <a href="{{ route('admin.kb.articles.edit', $article) }}">Edit</a>@endif</div>
        </footer>
        @if ($neighbours['prev'] || $neighbours['next'])
        <nav class="pager" aria-label="More articles">
            @if ($neighbours['prev'])<a href="{{ $kb->articleUrl($neighbours['prev']) }}" class="prev"><span class="muted small">Previous</span><b>{{ $neighbours['prev']->title }}</b></a>@else<span></span>@endif
            @if ($neighbours['next'])<a href="{{ $kb->articleUrl($neighbours['next']) }}" class="next"><span class="muted small">Next</span><b>{{ $neighbours['next']->title }}</b></a>@endif
        </nav>@endif
    </div>
    @if (count($toc) > 1)
    <aside class="docs-toc" aria-label="On this page"><b>On this page</b><ul>@foreach ($toc as $h)<li class="l{{ $h['level'] }}"><a href="#{{ $h['id'] }}">{{ $h['text'] }}</a></li>@endforeach</ul></aside>
    @endif
</div>
@endsection
