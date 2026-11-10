@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Products')
@push('head') @include('admin.partials.filters-style') @endpush
@section('content')
<x-page-head title="Products" sub="What you sell, and what it costs."><a class="btn primary" href="{{ route('admin.products.create') }}"><x-icon name="plus" :size="15" /> New product</a></x-page-head>
<x-view-tabs :tabs="['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'hidden' => 'Hidden from store']" :current="$view" :counts="$counts" label="Product views" />
@php($dir = $dir ?? 'asc')
<x-card :flush="true">
    @if ($products->isEmpty())
        @if ($view !== 'all')<x-empty icon="package" title="No products in this view" text="Try another tab."><a class="btn sm" href="{{ route('admin.products.index') }}">Show all products</a></x-empty>
        @else<x-empty icon="package" title="No products yet" text="Create your first product to start taking orders."><a class="btn primary sm" href="{{ route('admin.products.create') }}">New product</a></x-empty>@endif
    @else
    <table class="t stack-sm"><thead><tr>
        <x-sort key="product" :sort="$sort ?? ''" :dir="$dir">Product</x-sort>
        <x-sort key="group" :sort="$sort ?? ''" :dir="$dir">Group</x-sort>
        <th>Module</th>
        <th class="right">Starting price</th>
        <x-sort key="live" :sort="$sort ?? ''" :dir="$dir" :right="true" default="desc">Live services</x-sort>
        <x-sort key="status" :sort="$sort ?? ''" :dir="$dir">Status</x-sort>
    </tr></thead><tbody>
    @foreach ($products as $p)
        @php($cheapest = $p->prices->sortBy('amount')->first())
        <tr class="click" onclick="location.href='{{ route('admin.products.edit', $p) }}'"><td data-label="Product"><a href="{{ route('admin.products.edit', $p) }}"><b>{{ $p->name }}</b></a>@if ($p->offersTrial()) <span class="pill brand">{{ $p->trial_days }}-day trial</span>@endif</td><td data-label="Group">{{ $p->group?->name ?? '—' }}</td>
        <td data-label="Module">{{ $p->module ? ($modules[$p->module] ?? $p->module) : '—' }}</td>
        <td class="right" data-label="Starting price">{{ $cheapest ? money($cheapest->amount, $cheapest->currency).' '.$cheapest->cycle->suffix() : '—' }}</td>
        <td class="right" data-label="Live services">{{ $p->live_count }}</td>
        <td data-label="Status"><span class="pill {{ $p->is_active ? 'good' : '' }}">{{ $p->is_active ? 'Active' : 'Inactive' }}</span>@unless ($p->is_visible)<div class="muted small">Hidden from store</div>@endunless</td></tr>
    @endforeach</tbody></table>@endif
</x-card>
<x-card title="Groups" :flush="true">
    <p class="help m-0 px-card">Groups organise the storefront and decide which plans a customer can switch between. Edit a name or order, then save that row.</p>
    <table class="t inline-edit stack-sm"><thead><tr><th>Name</th><th>Order</th><th class="right">Products</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody>
    @forelse ($groups as $g)
        <tr>
            <td data-label="Name"><input type="text" name="name" form="grp-{{ $g->id }}" value="{{ $g->name }}" required aria-label="Name of group {{ $g->name }}" data-orig="{{ $g->name }}"></td>
            <td data-label="Order"><input type="number" name="sort" form="grp-{{ $g->id }}" min="0" value="{{ $g->sort }}" aria-label="Sort order of group {{ $g->name }}" data-orig="{{ $g->sort }}"></td>
            <td class="right" data-label="Products">{{ $g->products_count }}</td>
            <td class="right"><div class="row end">
                <button class="btn" type="submit" form="grp-{{ $g->id }}" disabled>Save</button>
                <button class="btn ghost danger" type="submit" form="grp-del-{{ $g->id }}">Delete</button></div></td>
        </tr>
    @empty<tr><td colspan="4" class="muted">No groups yet. Add one below.</td></tr>@endforelse
    </tbody></table>
    @foreach ($groups as $g)
        <form id="grp-{{ $g->id }}" method="POST" action="{{ route('admin.product-groups.update', $g) }}" data-group-form>@csrf @method('PUT')</form>
        <form id="grp-del-{{ $g->id }}" method="POST" action="{{ route('admin.product-groups.destroy', $g) }}" data-confirm="Delete group “{{ $g->name }}”? Its {{ $g->products_count }} {{ \Illuminate\Support\Str::plural('product', $g->products_count) }} become ungrouped." data-confirm-label="Delete group" data-danger>@csrf @method('DELETE')</form>
    @endforeach
    <form method="POST" action="{{ route('admin.product-groups.store') }}" class="row end px-card form-foot">@csrf
        <label for="new-group"><span>New group</span><input id="new-group" name="name" placeholder="e.g. Web hosting" required></label><button class="btn" type="submit">Add group</button>
    </form>
</x-card>
@push('scripts')
<script>
document.querySelectorAll('[data-group-form]').forEach(function (f) {
    var inputs = document.querySelectorAll('input[form="' + f.id + '"]'), save = document.querySelector('button[form="' + f.id + '"]');
    function sync() { var dirty = Array.prototype.some.call(inputs, function (i) { return i.value !== i.dataset.orig; }); save.disabled = !dirty; }
    inputs.forEach(function (i) { i.addEventListener('input', sync); });
});
</script>
@endpush
@endsection
