@extends('layouts.app', ['area' => 'admin'])
@php($editing = $article->exists)
@section('title', $editing ? 'Edit '.$article->title : 'Add article')
@section('content')
<x-page-head :title="$editing ? 'Edit article' : 'Add article'" :back="route('admin.kb.index')" backLabel="Knowledge base">
    @if ($editing && $article->isPublished())<a class="btn" href="{{ $kb->articleUrl($article) }}" target="_blank" rel="noopener"><x-icon name="external-link" :size="15" /> View</a>@elseif ($editing)<a class="btn" href="{{ $kb->articleUrl($article) }}" target="_blank" rel="noopener"><x-icon name="eye" :size="15" /> Preview draft</a>@endif
</x-page-head>
<form method="POST" action="{{ $editing ? route('admin.kb.articles.update', $article) : route('admin.kb.articles.store') }}" class="editor-layout" data-validate>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="card editor-main">
        <div class="setting-b">
            <x-field name="title" label="Title" :value="$article->title" aria-required="true" maxlength="160" />
            <div>
                <span class="label-text">Article</span>
                <textarea name="body" id="f-body" class="hidden" aria-label="Article body (Markdown)">{{ old('body', $article->body) }}</textarea>
                <div class="md-editor" data-md-editor data-script="{{ asset('assets/vendor/toastui/toastui-editor.js') }}" data-css="{{ asset('assets/vendor/toastui/toastui-editor.css') }}" data-css-dark="{{ asset('assets/vendor/toastui/toastui-editor-dark.css') }}" data-upload="{{ route('admin.kb.upload') }}" data-for="f-body"><p class="muted small m-0">Loading the editor…</p></div>
                @error('body')<span class="field-error">{{ $message }}</span>@enderror
                <p class="help">Switch between Markdown and Write with the tabs under the editor. Callout: start a quote with <code>[!NOTE]</code>, <code>[!TIP]</code>, <code>[!IMPORTANT]</code>, <code>[!WARNING]</code> or <code>[!CAUTION]</code>. Keyboard keys: <code>&lt;kbd&gt;Ctrl&lt;/kbd&gt;</code>.</p>
            </div>
        </div>
    </div>
    <aside class="editor-side">
        <div class="card setting">
            <div class="setting-h"><h2>Publish</h2></div>
            <div class="setting-b">
                <x-field name="status" label="Status" type="select" :value="$article->status ?: 'draft'" :options="['draft' => 'Draft: only staff can see it', 'published' => 'Published']" />
                <x-field name="category_id" label="Category" type="select" :value="$article->category_id" :options="['' => 'No category'] + collect($options)->pluck('label', 'id')->all()" />
            </div>
            <div class="setting-f"><span class="help">@if ($editing)Updated {{ $article->updated_at->diffForHumans() }}@endif</span><div class="row"><button class="btn primary sm" type="submit">{{ $editing ? 'Save' : 'Create' }}</button></div></div>
        </div>
        <div class="card setting">
            <div class="setting-h"><h2>Details</h2></div>
            <div class="setting-b">
                <x-field name="summary" label="Summary (optional)" type="textarea" rows="3" :value="$article->summary" maxlength="300" help="Shown in lists and search results. Leave blank to use the start of the article." />
                <x-field name="slug" label="Link name (optional)" :value="$article->slug" help="The last part of the web address. Made from the title if blank." />
            </div>
        </div>
        @if ($editing)
        <div class="card setting">
            <div class="setting-h"><h2>Feedback</h2></div>
            <div class="setting-b"><dl class="kv m-0"><dt>Views</dt><dd>{{ number_format($article->views) }}</dd><dt>Found helpful</dt><dd>@php($votes = $article->helpful_yes + $article->helpful_no){{ $votes ? round($article->helpful_yes / $votes * 100).'% of '.$votes.' '.\Illuminate\Support\Str::plural('vote', $votes) : 'No votes yet' }}</dd></dl></div>
        </div>
        @endif
    </aside>
</form>
@if ($editing)
<form method="POST" action="{{ route('admin.kb.articles.destroy', $article) }}" class="card setting danger-zone mt-24" data-confirm="Delete this article?">
    @csrf @method('DELETE')
    <div class="setting-h"><h2>Delete article</h2><p>Removes the article for good. Links to it will stop working.</p></div>
    <div class="setting-f"><span></span><div class="row"><button class="btn danger sm" type="submit">Delete article</button></div></div>
</form>
@endif
@endsection
