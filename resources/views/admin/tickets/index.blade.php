@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Tickets')
@push('head') @include('admin.partials.filters-style') @endpush
@section('content')
<x-page-head title="Tickets" sub="Support requests from your customers.">
    <a class="btn primary" href="{{ route('admin.tickets.create') }}"><x-icon name="plus" :size="15" /> New ticket</a>
</x-page-head>
<nav class="tabs page-tabs" aria-label="Ticket views">
    @foreach ($tabs as $key => $label)<a href="{{ route('admin.tickets.index', ['tab' => $key]) }}" class="{{ $tab === $key ? 'on' : '' }}">{{ $label }} <span class="muted small">{{ $counts[$key] }}</span></a>@endforeach
</nav>
<form class="filters" method="GET" action="{{ route('admin.tickets.index') }}">
    <input type="hidden" name="tab" value="{{ $tab }}">
    <div class="searchbox"><x-icon name="search" :size="15" /><input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Number, subject, requester…"></div>
    <select name="department_id" aria-label="Department"><option value="">All departments</option>@foreach ($departments as $d)<option value="{{ $d->id }}" @selected((int) ($filters['department_id'] ?? 0) === $d->id)>{{ $d->name }}</option>@endforeach</select>
    <select name="status" aria-label="Status"><option value="">Any status</option>@foreach (\App\Enums\TicketStatus::cases() as $s)<option value="{{ $s->value }}" @selected(($filters['status'] ?? '') === $s->value)>{{ $s->label() }}</option>@endforeach</select>
    <select name="priority" aria-label="Priority"><option value="">Any priority</option>@foreach (\App\Enums\TicketPriority::cases() as $p)<option value="{{ $p->value }}" @selected(($filters['priority'] ?? '') === $p->value)>{{ $p->label() }}</option>@endforeach</select>
    <button class="btn" type="submit">Filter</button>
    @if ($filters)<a class="btn ghost" href="{{ route('admin.tickets.index', ['tab' => $tab]) }}">Clear</a>@endif
</form>
<x-card :flush="true">
    @if ($tickets->isEmpty())
        <x-empty icon="life-buoy" title="No tickets here" text="Nothing matches this view." />
    @else
    <table class="t stack-sm"><thead><tr><th>Ticket</th><th>Requester</th><th>Department</th><th>Status</th><th>Priority</th><th>Assigned</th><th>Last reply</th></tr></thead><tbody>
        @foreach ($tickets as $t)
            <tr class="click" onclick="location.href='{{ route('admin.tickets.show', $t) }}'">
                <td data-label="Ticket"><a href="{{ route('admin.tickets.show', $t) }}"><b>{{ $t->number }}</b></a>@if ($t->is_locked) <x-icon name="lock" :size="12" />@endif<div>{{ \Illuminate\Support\Str::limit($t->subject, 70) }}</div></td>
                <td data-label="Requester">{{ $t->requester_name }}<div class="muted small">{{ $t->requester_email }}</div></td>
                <td data-label="Department">{{ $t->department?->name ?? '—' }}</td>
                <td data-label="Status"><x-status :status="$t->status" /></td>
                <td data-label="Priority"><x-status :status="$t->priority" /></td>
                <td data-label="Assigned">{{ $t->assignee?->name ?? '—' }}</td>
                <td data-label="Last reply" class="muted">{{ $t->last_reply_at?->diffForHumans() ?? '—' }}@if ($t->last_reply_by)<div class="small">by {{ $t->last_reply_by }}</div>@endif</td>
            </tr>
        @endforeach
    </tbody></table>
    {{ $tickets->links() }}
    @endif
</x-card>
@endsection
