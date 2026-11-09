@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Invoices')
@push('head') @include('admin.partials.filters-style') @endpush
@section('content')
<x-page-head title="Invoices" sub="Everything you've billed, and what's still owed.">
    <a class="btn primary" href="{{ route('admin.invoices.create') }}"><x-icon name="plus" :size="15" /> New invoice</a>
</x-page-head>
<div class="stats">
    <x-stat label="Outstanding" icon="receipt" :value="$outstanding->isEmpty() ? money(0) : $outstanding->map(fn ($v, $c) => money((int) $v, $c))->implode(' · ')" hint="Open invoices, unpaid balance" />
    <x-stat label="Overdue" icon="triangle-alert" :value="$overdue->isEmpty() ? money(0) : $overdue->map(fn ($v, $c) => money((int) $v, $c))->implode(' · ')" hint="Past their due date" />
</div>
<form class="filters" method="GET" action="{{ route('admin.invoices.index') }}">
    <div class="searchbox"><x-icon name="search" :size="15" /><input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Number, reference, client…"></div>
    <select name="status" aria-label="Status"><option value="">All statuses</option>@foreach (\App\Enums\InvoiceStatus::cases() as $s)<option value="{{ $s->value }}" @selected(($filters['status'] ?? '') === $s->value)>{{ $s->label() }}</option>@endforeach</select>
    <select name="client_id" aria-label="Client"><option value="">All clients</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) ($filters['client_id'] ?? 0) === $c->id)>{{ $c->company_name ?: $c->name }}</option>@endforeach</select>
    <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" aria-label="Issued from"><input type="date" name="to" value="{{ $filters['to'] ?? '' }}" aria-label="Issued to">
    <label class="check"><input type="checkbox" name="overdue" value="1" @checked(! empty($filters['overdue']))> Overdue only</label>
    <button class="btn" type="submit">Filter</button>
    @if (array_filter($filters))<a class="btn ghost" href="{{ route('admin.invoices.index') }}">Clear</a>@endif
</form>
<x-card :flush="true">
    @if ($invoices->isEmpty())
        <x-empty icon="receipt" title="No invoices found" text="Invoices appear here when clients order services, renewals come due, or you create one by hand."><a class="btn primary" href="{{ route('admin.invoices.create') }}">New invoice</a></x-empty>
    @else
    <table class="t stack-sm"><thead><tr><th>Invoice</th><th>Client</th><th>Status</th><th>Issued</th><th>Due</th><th class="right">Total</th><th class="right">Balance</th></tr></thead><tbody>
        @foreach ($invoices as $i)
            <tr class="click" onclick="location.href='{{ route('admin.invoices.show', $i) }}'">
                <td data-label="Invoice"><a href="{{ route('admin.invoices.show', $i) }}"><b>{{ $i->displayNumber() }}</b></a></td>
                <td data-label="Client">{{ $i->client->displayName() }}</td>
                <td data-label="Status"><x-status :status="$i->status" />@if ($i->isOverdue()) <span class="pill bad">Overdue</span>@endif</td>
                <td data-label="Issued" class="muted">{{ $i->issue_date?->format('j M Y') ?? '—' }}</td>
                <td data-label="Due" class="muted">{{ $i->due_date?->format('j M Y') ?? '—' }}</td>
                <td data-label="Total" class="right">{{ money($i->total, $i->currency) }}</td>
                <td data-label="Balance" class="right">{{ $i->status->value === 'open' ? money($i->balance(), $i->currency) : '—' }}</td>
            </tr>
        @endforeach
    </tbody></table>
    {{ $invoices->links() }}
    @endif
</x-card>
@endsection
