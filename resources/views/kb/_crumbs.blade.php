<nav class="crumb-trail" aria-label="Breadcrumb">
    <a href="{{ route('kb.index') }}">{{ \App\Services\Knowledgebase\Knowledgebase::title() }}</a>
    @foreach ($crumbs as $c)<x-icon name="chevron-right" :size="12" />@if (! $loop->last || ! empty($lastLink))<a href="{{ $kb->categoryUrl($c) }}">{{ $c->name }}</a>@else<span aria-current="page">{{ $c->name }}</span>@endif @endforeach
</nav>
