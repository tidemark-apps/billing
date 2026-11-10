@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Clients')
@push('head') @include('admin.partials.filters-style') @endpush
@section('content')
<x-page-head title="Clients" sub="Everyone you bill."><a class="btn primary" href="{{ route('admin.clients.create') }}"><x-icon name="plus" :size="15" /> New client</a></x-page-head>
<x-view-tabs :tabs="['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'internal' => 'Internal', 'closed' => 'Closed']" :current="$view" :counts="$counts" label="Client views" />
<form method="GET" class="filters" action="{{ route('admin.clients.index') }}">
    @if ($view !== 'all')<input type="hidden" name="view" value="{{ $view }}">@endif
    @if (request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}"><input type="hidden" name="dir" value="{{ request('dir') }}">@endif
    <div class="searchbox"><x-icon name="search" :size="15" /><input type="search" name="q" value="{{ request('q') }}" placeholder="Search name, company or email…" aria-label="Search clients"></div>
    <button class="btn" type="submit">Search</button>
    @if (request()->filled('q'))<a class="btn ghost" href="{{ route('admin.clients.index', array_filter(['view' => $view === 'all' ? null : $view, 'sort' => request('sort'), 'dir' => request('dir')])) }}">Clear search</a>@endif
</form>
<x-card :flush="true">
    @if ($clients->isEmpty())
        @if (request()->filled('q') || $view !== 'all')
            <x-empty icon="users" title="No clients match" text="Nothing fits this view{{ request()->filled('q') ? ' and search' : '' }}. Try another tab or clear the search."><a class="btn sm" href="{{ route('admin.clients.index') }}">Show all clients</a></x-empty>
        @else
            <x-empty icon="users" title="No clients yet" text="Clients appear when someone signs up or orders. You can also add one by hand."><a class="btn primary sm" href="{{ route('admin.clients.create') }}">New client</a></x-empty>
        @endif
    @else
    <table class="t stack-sm"><thead><tr>
        <x-sort key="client" :sort="$sort" :dir="$dir">Client</x-sort>
        <x-sort key="email" :sort="$sort" :dir="$dir">Email</x-sort>
        <x-sort key="country" :sort="$sort" :dir="$dir">Country</x-sort>
        <x-sort key="services" :sort="$sort" :dir="$dir" :right="true" default="desc">Live services</x-sort>
        <x-sort key="status" :sort="$sort" :dir="$dir">Status</x-sort>
        <x-sort key="since" :sort="$sort" :dir="$dir" default="desc">Client since</x-sort>
    </tr></thead><tbody>
        @foreach ($clients as $c)
        <tr class="click" onclick="location.href='{{ route('admin.clients.show', $c) }}'"><td data-label="Client"><div class="row"><a href="{{ route('admin.clients.show', $c) }}"><b>{{ $c->displayName() }}</b></a>@if ($c->is_internal)<span class="pill brand" title="Services are free and this account is left out of reports">Internal</span>@endif</div>@if($c->company_name)<div class="muted small">{{ $c->name }}</div>@endif</td>
            <td data-label="Email">{{ $c->email }}</td><td data-label="Country">{{ \App\Support\Countries::name($c->country) ?: '—' }}</td>
            <td class="right" data-label="Live services">{{ $c->live_services_count }}</td>
            <td data-label="Status"><span class="pill {{ $c->status === 'active' ? 'good' : '' }}">{{ ucfirst($c->status) }}</span></td>
            <td data-label="Client since" class="muted">{{ $c->created_at->format('j M Y') }}</td></tr>
        @endforeach
    </tbody></table>
    {{ $clients->links() }}
    @endif
</x-card>
@endsection
