@extends('layouts.app', ['area' => 'admin'])
@php($editing = $category->exists)
@section('title', $editing ? 'Edit '.$category->name : 'Add category')
@section('content')
<x-page-head :title="$editing ? $category->name : 'Add category'" :sub="$editing ? 'Name, place and visibility of this category.' : 'Categories group articles and can nest inside each other.'" :back="route('admin.kb.index')" backLabel="Knowledge base" />
<form method="POST" action="{{ $editing ? route('admin.kb.categories.update', $category) : route('admin.kb.categories.store') }}" class="card setting form-narrow" data-validate>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="setting-h"><h2>Category</h2><p>Shown as a card on the help center home page and as a group in the side menu.</p></div>
    <div class="setting-b">
        <x-field name="name" label="Name" :value="$category->name" aria-required="true" />
        <x-field name="parent_id" label="Inside" type="select" :value="$category->parent_id" :options="['' => 'Top level'] + collect($options)->pluck('label', 'id')->all()" help="Categories can nest as deep as you like." />
        <x-field name="description" label="Description (optional)" type="textarea" :value="$category->description" help="One sentence under the name on the home page and category page." />
        <div class="grid2">
            <x-field name="slug" label="Link name (optional)" :value="$category->slug" help="The last part of the web address. Made from the name if blank." />
            <x-field name="icon" label="Icon (optional)" :value="$category->icon" placeholder="book-open" help="A Lucide icon name, for example rocket or settings." />
        </div>
        <x-field name="is_visible" type="checkbox" label="Visible to customers" :value="$editing ? $category->is_visible : true" help="Turn off to keep the category, and everything inside it, for staff only." />
    </div>
    <div class="setting-f"><span class="help">@if ($editing)Address: <code>/kb/{{ $kb->categoryPath($category) }}</code>@endif</span><div class="row"><button class="btn primary sm" type="submit">{{ $editing ? 'Save' : 'Add category' }}</button></div></div>
</form>
@if ($editing)
<form method="POST" action="{{ route('admin.kb.categories.destroy', $category) }}" class="card setting danger-zone form-narrow mt-24" data-confirm="Delete this category?">
    @csrf @method('DELETE')
    <div class="setting-h"><h2>Delete category</h2><p>Only an empty category can be deleted. Move or delete its articles and subcategories first.</p></div>
    @error('category')<div class="setting-b"><span class="field-error">{{ $message }}</span></div>@enderror
    <div class="setting-f"><span class="help">{{ $articles }} {{ \Illuminate\Support\Str::plural('article', $articles) }} and {{ $children }} {{ \Illuminate\Support\Str::plural('subcategory', $children) }} inside.</span><div class="row"><button class="btn danger sm" type="submit" @disabled($articles || $children)>Delete category</button></div></div>
</form>
@endif
@endsection
