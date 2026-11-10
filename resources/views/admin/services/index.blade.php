@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Services')
@push('head') @include('admin.partials.filters-style') @endpush
@section('content')
@php($filtered = request()->filled('q') || request()->filled('product') || request()->filled('client'))
<x-page-head title="Services" sub="Everything your clients have ordered."><a class="btn primary" href="{{ route('admin.services.create') }}"><x-icon name="plus" :size="15" /> New service</a></x-page-head>
<x-view-tabs :tabs="['all' => 'All', 'pending' => 'Pending', 'active' => 'Active', 'suspended' => 'Suspended', 'trial' => 'Trial', 'cancelled' => 'Cancelled']" :current="$view" :counts="$counts" label="Service views" />
<form method="GET" class="filters" action="{{ route('admin.services.index') }}">
    @if ($view !== 'all')<input type="hidden" name="view" value="{{ $view }}">@endif
    @if (request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}"><input type="hidden" name="dir" value="{{ request('dir') }}">@endif
    @if (request('client'))<input type="hidden" name="client" value="{{ request('client') }}">@endif
    <div class="searchbox"><x-icon name="search" :size="15" /><input type="search" name="q" value="{{ request('q') }}" placeholder="Search label or client…" aria-label="Search services"></div>
    <select name="product" aria-label="Product"><option value="">All products</option>@foreach ($products as $id => $n)<option value="{{ $id }}" @selected((string) request('product') === (string) $id)>{{ $n }}</option>@endforeach</select>
    <button class="btn" type="submit">Apply</button>
    @if ($filtered)<a class="btn ghost" href="{{ route('admin.services.index', array_filter(['view' => $view === 'all' ? null : $view])) }}">Clear filters</a>@endif
</form>
@if (request()->filled('client') && ($fc = \App\Models\Client::find(request()->integer('client'))))
<div class="filter-chips"><span class="pill">Client: {{ $fc->displayName() }} <a href="{{ request()->fullUrlWithQuery(['client' => null, 'page' => null]) }}" aria-label="Remove client filter">×</a></span></div>
@endif
<x-card :flush="true">
    @if ($services->isEmpty())
        @if ($filtered || $view !== 'all')
            <x-empty icon="boxes" title="No services match" text="Nothing fits this view and filters. Try another tab or clear the filters."><a class="btn sm" href="{{ route('admin.services.index') }}">Show all services</a></x-empty>
        @else
            <x-empty icon="boxes" title="No services yet" text="Services are created when clients order a product. You can also add one by hand."><a class="btn primary sm" href="{{ route('admin.services.create') }}">New service</a></x-empty>
        @endif
    @else
    <table class="t stack-sm"><thead><tr>
        <x-sort key="service" :sort="$sort" :dir="$dir">Service</x-sort>
        <x-sort key="client" :sort="$sort" :dir="$dir">Client</x-sort>
        <x-sort key="status" :sort="$sort" :dir="$dir">Status</x-sort>
        <x-sort key="cycle" :sort="$sort" :dir="$dir">Cycle</x-sort>
        <x-sort key="price" :sort="$sort" :dir="$dir" :right="true">Price</x-sort>
        <x-sort key="due" :sort="$sort" :dir="$dir">Next due</x-sort>
    </tr></thead><tbody>
    @foreach ($services as $s)
        <tr class="click" onclick="location.href='{{ route('admin.services.show', $s) }}'"><td data-label="Service"><a href="{{ route('admin.services.show', $s) }}"><b>{{ $s->title() }}</b></a>@if ($s->provision_error)<div class="small bad-text">Provisioning error</div>@endif</td>
        <td data-label="Client"><a href="{{ route('admin.clients.show', $s->client_id) }}">{{ $s->client->displayName() }}</a></td>
        <td data-label="Status">@if ($s->onTrial() && $s->status === \App\Enums\ServiceStatus::Active)<span class="pill warn" title="Ends {{ $s->trial_ends_at->format('j M Y') }}">Trial</span>@else<x-status :status="$s->status" />@endif</td><td data-label="Cycle">{{ $s->billing_cycle->label() }}</td>
        <td class="right" data-label="Price">{{ money($s->amount, $s->currency) }}</td><td data-label="Next due" class="muted">{{ $s->next_due_date?->format('j M Y') ?? '—' }}</td></tr>
    @endforeach</tbody></table>
    {{ $services->links() }}@endif
</x-card>
@endsection
