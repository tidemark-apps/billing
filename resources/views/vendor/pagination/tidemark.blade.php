@if ($paginator->hasPages())
<nav class="table-foot" role="navigation" aria-label="Pagination">
    <span>{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</span>
    <span class="row">
        @if ($paginator->onFirstPage())<span class="btn sm disabled">Previous</span>@else<a class="btn sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>@endif
        @if ($paginator->hasMorePages())<a class="btn sm" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>@else<span class="btn sm disabled">Next</span>@endif
    </span>
</nav>
@endif
