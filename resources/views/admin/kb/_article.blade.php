<div class="tree-row" style="--depth: {{ $depth }}" role="treeitem">
    <span class="tree-name"><x-icon name="file-text" :size="15" /> <a href="{{ route('admin.kb.articles.edit', $a) }}">{{ $a->title }}</a> <span class="pill {{ $a->isPublished() ? 'good' : '' }}">{{ $a->isPublished() ? 'Published' : 'Draft' }}</span></span>
    <span class="tree-actions">
        <span class="muted small nowrap">{{ number_format($a->views) }} {{ \Illuminate\Support\Str::plural('view', $a->views) }} · Updated {{ $a->updated_at->diffForHumans() }}</span>
        <form method="POST" action="{{ route('admin.kb.articles.move', $a) }}" class="row">@csrf
            <button class="btn sm ghost icon" name="direction" value="up" aria-label="Move {{ $a->title }} up" @disabled($index === 0)><x-icon name="chevron-up" :size="14" /></button>
            <button class="btn sm ghost icon" name="direction" value="down" aria-label="Move {{ $a->title }} down" @disabled($index === $siblings - 1)><x-icon name="chevron-down" :size="14" /></button>
        </form>
    </span>
</div>
