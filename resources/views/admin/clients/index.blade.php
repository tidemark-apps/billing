@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Clients')
@section('content')
<x-page-head title="Clients" sub="Everyone you bill."><a class="btn primary" href="{{ route('admin.clients.create') }}"><x-icon name="plus" :size="15" /> New client</a></x-page-head>
<form method="GET" class="filters">
    <div class="searchbox"><x-icon name="search" :size="15" /><input type="search" name="q" value="{{ request('q') }}" placeholder="Search name, company, email"></div>
    <select name="status" onchange="this.form.submit()"><option value="">All statuses</option>@foreach (['active', 'inactive', 'closed'] as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>@endforeach</select>
    <button class="btn" type="submit">Filter</button>
</form>
<x-card :flush="true">
    @if ($clients->isEmpty())
        <x-empty icon="users" title="No clients found" text="Add a client or adjust your search."><a class="btn primary sm" href="{{ route('admin.clients.create') }}">New client</a></x-empty>
    @else
    <table class="t stack-sm"><thead><tr><th>Client</th><th>Email</th><th>Country</th><th class="right">Live services</th><th>Status</th><th>Since</th></tr></thead><tbody>
        @foreach ($clients as $c)
        <tr class="click"><td data-label="Client"><a href="{{ route('admin.clients.show', $c) }}"><b>{{ $c->displayName() }}</b></a>@if($c->company_name)<div class="muted small">{{ $c->name }}</div>@endif</td>
            <td data-label="Email">{{ $c->email }}</td><td data-label="Country">{{ \App\Support\Countries::name($c->country) }}</td>
            <td class="right" data-label="Services">{{ $c->live_services_count }}</td>
            <td data-label="Status"><span class="pill {{ $c->status === 'active' ? 'good' : '' }}">{{ ucfirst($c->status) }}</span>@if ($c->is_internal) <span class="pill brand">Internal</span>@endif</td>
            <td data-label="Since">{{ $c->created_at->format('j M Y') }}</td></tr>
        @endforeach
    </tbody></table>
    {{ $clients->links() }}
    @endif
</x-card>
@endsection
