@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Support tickets')
@section('content')
<x-page-head title="Support tickets" sub="Ask us for help and follow the conversation here.">
    <a class="btn primary" href="{{ route('portal.tickets.create') }}"><x-icon name="plus" :size="15" /> New ticket</a>
</x-page-head>
<x-card :flush="true">
@if ($tickets->isEmpty())
    <x-empty icon="life-buoy" title="No tickets yet" text="When you need help, open a ticket and we will get back to you."><a class="btn primary sm" href="{{ route('portal.tickets.create') }}">Open a ticket</a></x-empty>
@else
<table class="t stack-sm"><thead><tr><th>Ticket</th><th>Department</th><th>Status</th><th>Last reply</th></tr></thead><tbody>
@foreach ($tickets as $t)
<tr>
    <td data-label="Ticket"><a href="{{ route('portal.tickets.show', $t->uuid) }}"><b>{{ $t->subject }}</b></a> <span class="muted small">{{ $t->number }}</span></td>
    <td data-label="Department">{{ $t->department?->name ?? '—' }}</td>
    <td data-label="Status"><x-status :status="$t->status" />@if ($t->status === \App\Enums\TicketStatus::Answered) <span class="muted small">Awaiting you</span>@endif @if ($t->is_locked)<span class="pill outline">Locked</span>@endif</td>
    <td data-label="Last reply">{{ $t->last_reply_at?->diffForHumans() ?? '—' }}@if ($t->last_reply_by) <span class="muted small">by {{ $t->last_reply_by === 'staff' ? 'us' : 'you' }}</span>@endif</td>
</tr>
@endforeach</tbody></table>
{{ $tickets->links() }}
@endif
</x-card>
@endsection
