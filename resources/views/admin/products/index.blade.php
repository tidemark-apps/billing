@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Products')
@section('content')
<x-page-head title="Products" sub="What you sell, and what it costs."><a class="btn primary" href="{{ route('admin.products.create') }}"><x-icon name="plus" :size="15" /> New product</a></x-page-head>
<x-card :flush="true">
    @if ($products->isEmpty())<x-empty icon="package" title="No products yet" text="Create your first product to start taking orders."><a class="btn primary sm" href="{{ route('admin.products.create') }}">New product</a></x-empty>@else
    <table class="t stack-sm"><thead><tr><th>Product</th><th>Group</th><th>Module</th><th>From</th><th class="right">Live</th><th>Status</th></tr></thead><tbody>
    @foreach ($products as $p)
        @php($cheapest = $p->prices->sortBy('amount')->first())
        <tr><td data-label="Product"><a href="{{ route('admin.products.edit', $p) }}"><b>{{ $p->name }}</b></a>@if ($p->offersTrial()) <span class="pill">{{ $p->trial_days }}-day trial</span>@endif</td><td data-label="Group">{{ $p->group?->name ?? '—' }}</td>
        <td data-label="Module">{{ $p->module ? ($modules[$p->module] ?? $p->module) : '—' }}</td>
        <td data-label="From">{{ $cheapest ? money($cheapest->amount, $cheapest->currency).' '.$cheapest->cycle->suffix() : '—' }}</td>
        <td class="right" data-label="Live">{{ $p->live_count }}</td>
        <td data-label="Status"><span class="pill {{ $p->is_active ? 'good' : '' }}">{{ $p->is_active ? 'Active' : 'Inactive' }}</span>@unless ($p->is_visible) <span class="pill">Hidden</span>@endunless</td></tr>
    @endforeach</tbody></table>@endif
</x-card>
<x-card title="Groups" :flush="true">
    <table class="t"><thead><tr><th>Name</th><th class="right">Products</th><th></th></tr></thead><tbody>
    @forelse ($groups as $g)
        <tr><td colspan="2" style="padding:0">
            <form method="POST" action="{{ route('admin.product-groups.update', $g) }}" class="row" style="padding:8px 20px">@csrf @method('PUT')
                <input name="name" value="{{ $g->name }}" required style="max-width:260px" aria-label="Group name"><input name="sort" type="number" min="0" value="{{ $g->sort }}" style="max-width:90px" aria-label="Sort order">
                <span class="muted small grow">{{ $g->products_count }} product(s)</span><button class="btn sm" type="submit">Save</button>
            </form></td>
            <td class="right"><form method="POST" action="{{ route('admin.product-groups.destroy', $g) }}" data-confirm="Delete group “{{ $g->name }}”? Its products become ungrouped." data-confirm-label="Delete" data-danger>@csrf @method('DELETE')<button class="btn sm ghost danger" type="submit">Delete</button></form></td></tr>
    @empty<tr><td colspan="3" class="muted">No groups yet. Groups organise the storefront.</td></tr>@endforelse
    </tbody></table>
    <form method="POST" action="{{ route('admin.product-groups.store') }}" class="row" style="padding:12px 20px;border-top:1px solid var(--border)">@csrf
        <input name="name" placeholder="New group name" required style="max-width:260px"><button class="btn sm" type="submit">Add group</button>
    </form>
</x-card>
@endsection
