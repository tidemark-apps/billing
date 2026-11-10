@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Services')
@section('content')
<x-page-head title="Services" sub="Everything your clients have ordered."><a class="btn primary" href="{{ route('admin.services.create') }}"><x-icon name="plus" :size="15" /> New service</a></x-page-head>
<form method="GET" class="filters">
    <div class="searchbox"><x-icon name="search" :size="15" /><input type="search" name="q" value="{{ request('q') }}" placeholder="Search label or client"></div>
    <select name="status" onchange="this.form.submit()"><option value="">All statuses</option>@foreach ($statuses as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select>
    <select name="product" onchange="this.form.submit()"><option value="">All products</option>@foreach ($products as $id => $n)<option value="{{ $id }}" @selected((string) request('product') === (string) $id)>{{ $n }}</option>@endforeach</select>
    @if (request('client'))<input type="hidden" name="client" value="{{ request('client') }}">@endif
    <button class="btn" type="submit">Filter</button>
</form>
<x-card :flush="true">
    @if ($services->isEmpty())<x-empty icon="boxes" title="No services found" />@else
    <table class="t stack-sm"><thead><tr><th>Service</th><th>Client</th><th>Status</th><th>Cycle</th><th class="right">Price</th><th>Next due</th></tr></thead><tbody>
    @foreach ($services as $s)
        <tr><td data-label="Service"><a href="{{ route('admin.services.show', $s) }}"><b>{{ $s->title() }}</b></a>@if ($s->provision_error)<div class="small" style="color:var(--destructive)">Provisioning error</div>@endif</td>
        <td data-label="Client"><a href="{{ route('admin.clients.show', $s->client_id) }}">{{ $s->client->displayName() }}</a></td>
        <td data-label="Status"><x-status :status="$s->status" />@if ($s->onTrial()) <span class="pill">Trial</span>@endif</td><td data-label="Cycle">{{ $s->billing_cycle->label() }}</td>
        <td class="right" data-label="Price">{{ money($s->amount, $s->currency) }}</td><td data-label="Next due">{{ $s->next_due_date?->format('j M Y') ?? '—' }}</td></tr>
    @endforeach</tbody></table>
    {{ $services->links() }}@endif
</x-card>
@endsection
