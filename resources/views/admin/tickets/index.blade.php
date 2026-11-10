@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Tickets')
@php
    $clearUrl = route('admin.tickets.index', array_filter(['tab' => $tab === 'open' ? null : $tab, 'sort' => request('sort'), 'dir' => request('dir')]));
    $mine = auth()->id();
@endphp
@section('content')
<x-page-head title="Tickets" sub="Support requests from your customers.">
    <a class="btn primary" href="{{ route('admin.tickets.create') }}"><x-icon name="plus" :size="15" /> New ticket</a>
</x-page-head>
<x-view-tabs :tabs="$tabs" :current="$tab" :counts="$counts" param="tab" label="Ticket views" />
<form class="filters" method="GET" action="{{ route('admin.tickets.index') }}">
    @if ($tab !== 'open')<input type="hidden" name="tab" value="{{ $tab }}">@endif
    @if (request()->filled('sort'))<input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="dir" value="{{ $dir }}">@endif
    <div class="searchbox"><x-icon name="search" :size="15" /><input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search number, subject or requester…" aria-label="Search tickets"></div>
    <select name="department_id" aria-label="Department"><option value="">All departments</option>@foreach ($departments as $d)<option value="{{ $d->id }}" @selected((int) ($filters['department_id'] ?? 0) === $d->id)>{{ $d->name }}</option>@endforeach</select>
    <select name="status" aria-label="Status"><option value="">Any status</option>@foreach (\App\Enums\TicketStatus::cases() as $s)<option value="{{ $s->value }}" @selected(($filters['status'] ?? '') === $s->value)>{{ $s->label() }}</option>@endforeach</select>
    <select name="priority" aria-label="Priority"><option value="">Any priority</option>@foreach (\App\Enums\TicketPriority::cases() as $p)<option value="{{ $p->value }}" @selected(($filters['priority'] ?? '') === $p->value)>{{ $p->label() }}</option>@endforeach</select>
    <button class="btn" type="submit">Apply</button>
    @if ($filters)<a class="btn ghost" href="{{ $clearUrl }}">Clear filters</a>@endif
</form>
<x-card :flush="true">
    @if ($tickets->isEmpty())
        @if ($filters)
            <x-empty icon="search" title="No tickets match these filters" text="Try a different search or clear the filters."><a class="btn sm" href="{{ $clearUrl }}">Clear filters</a></x-empty>
        @else
            <x-empty icon="life-buoy" :title="match ($tab) { 'mine' => 'Nothing assigned to you', 'unassigned' => 'Every open ticket has an owner', 'awaiting' => 'Nobody is waiting on a reply', 'closed' => 'No closed tickets yet', default => 'No open tickets' }" text="New requests from the customer portal and from email show up here." />
        @endif
    @else
    <table class="t stack-sm">
        <thead><tr>
            <x-sort key="ticket" :sort="$sort" :dir="$dir" default="asc">Ticket</x-sort>
            <th>Requester</th>
            <th>Department</th>
            <x-sort key="priority" :sort="$sort" :dir="$dir">Status and priority</x-sort>
            <th>Assigned</th>
            <x-sort key="activity" :sort="$sort" :dir="$dir">Waiting or last reply</x-sort>
        </tr></thead>
        <tbody>
        @foreach ($tickets as $t)
            @php
                $needs = $t->status->awaitsStaff();
                $since = $t->last_reply_at ?? $t->created_at;
                $stale = $needs && $since->lt(now()->subDay());
            @endphp
            <tr class="click {{ $needs ? 'unread' : '' }}" onclick="location.href='{{ route('admin.tickets.show', $t) }}'">
                <td data-label="Ticket">
                    <a href="{{ route('admin.tickets.show', $t) }}" class="subject">@if ($needs)<span class="dot brand" title="Needs a reply"></span><span class="sr-only">Needs a reply: </span>@endif{{ \Illuminate\Support\Str::limit($t->subject, 70) }}</a>
                    <div class="muted small">{{ $t->number }}@if ($t->is_locked) · <x-icon name="lock" :size="11" /> Locked @endif</div>
                </td>
                <td data-label="Requester">{{ $t->requester_name }}<div class="muted small">{{ $t->requester_email }}</div></td>
                <td data-label="Department">{{ $t->department?->name ?? '—' }}</td>
                <td data-label="Status">
                    <x-status :status="$t->status" />
                    @if (in_array($t->priority->value, ['high', 'urgent'], true))<div class="small mt-8 {{ $t->priority->value === 'urgent' ? 'bad-text' : 'warn-text' }}"><x-icon name="flag" :size="11" /> {{ $t->priority->label() }} priority</div>@endif
                </td>
                <td data-label="Assigned">@if ($t->assigned_to === $mine)<b>You</b>@else{{ $t->assignee?->name ?? '—' }}@endif</td>
                <td data-label="Activity">
                    @if ($needs)<b class="{{ $stale ? 'bad-text' : '' }}">Waiting {{ $since->diffForHumans(null, true) }}</b><div class="muted small">since {{ $since->format('j M H:i') }}</div>
                    @else<span class="muted">{{ $t->last_reply_at?->diffForHumans() ?? '—' }}</span>@if ($t->last_reply_by)<div class="muted small">by {{ $t->last_reply_by === 'staff' ? 'staff' : 'customer' }}</div>@endif @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    {{ $tickets->links() }}
    @endif
</x-card>
@endsection
