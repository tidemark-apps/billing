@extends(auth()->check() ? 'layouts.app' : 'layouts.public', ['area' => 'portal']) {{-- signed in: the client-area shell; guests: the plain public one --}}
@section('title', 'Search · '.\App\Services\Knowledgebase\Knowledgebase::title())
@section('content')
<div class="docs">
    @include('kb._nav', ['root' => collect()])
    <div class="docs-main">
        <nav class="crumb-trail"><a href="{{ route('kb.index') }}">{{ \App\Services\Knowledgebase\Knowledgebase::title() }}</a><x-icon name="chevron-right" :size="12" /><span aria-current="page">Search</span></nav>
        <header class="docs-title"><h1>@if ($q !== '')Results for “{{ $q }}”@else Search @endif</h1></header>
        @if ($q === '')<p class="muted">Type a few words to find an article.</p>
        @elseif ($results->isEmpty())<x-empty icon="search" title="No matching articles" text="Try different words, or browse the categories on the left." />
        @else<p class="muted small">{{ $results->count() }} {{ \Illuminate\Support\Str::plural('result', $results->count()) }}</p>
        <ul class="article-list">@foreach ($results as $a)<li><a href="{{ $kb->articleUrl($a) }}"><b>{{ $a->title }}</b>@if ($trail = $kb->ancestry($a->category_id ? \App\Models\KbCategory::find($a->category_id) : null)->pluck('name')->implode(' › '))<span class="small muted">{{ $trail }}</span>@endif<span class="muted">{{ $a->snippet }}</span></a></li>@endforeach</ul>@endif
    </div>
</div>
@endsection
