@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Support tickets')
@section('content')
<x-page-head title="Support tickets" sub="Ask us for help and follow the conversation here.">
    <a class="btn primary" href="{{ route('portal.tickets.create') }}"><x-icon name="plus" :size="15" /> New ticket</a>
</x-page-head>
<x-view-tabs :tabs="['open' => 'Open', 'closed' => 'Closed']" :current="$view" :counts="$counts" label="Ticket views" />
<x-card :flush="true">
@if ($tickets->isEmpty())
    @if ($view === 'closed')
        <x-empty icon="life-buoy" title="No closed tickets" text="Tickets you or we close will be kept here." />
    @elseif ($counts['closed'] > 0)
        <x-empty icon="circle-check" title="Nothing waiting" text="You have no open tickets. Past conversations are under Closed."><a class="btn primary sm" href="{{ route('portal.tickets.create') }}">Open a ticket</a></x-empty>
    @else
        <x-empty icon="life-buoy" title="No tickets yet" text="When you need help, open a ticket and we will get back to you."><a class="btn primary sm" href="{{ route('portal.tickets.create') }}">Open a ticket</a></x-empty>
    @endif
@else
<table class="t stack-sm"><thead><tr><th>Ticket</th><th>Department</th><th>Status</th><th>Last update</th></tr></thead><tbody>
@foreach ($tickets as $t)
@php($yourTurn = $t->status === \App\Enums\TicketStatus::Answered && ! $t->is_locked)
<tr class="click {{ $yourTurn ? 'unread' : '' }}" onclick="location.href='{{ route('portal.tickets.show', $t->uuid) }}'">
    <td data-label="Ticket"><a class="subject" href="{{ route('portal.tickets.show', $t->uuid) }}">{{ $t->subject }}</a><div class="muted small">{{ $t->number }}</div></td>
    <td data-label="Department">{{ $t->department?->name ?? '—' }}</td>
    <td data-label="Status"><x-support-ticket-status :ticket="$t" />@if ($yourTurn)<div class="small mt-8"><b>Your reply is needed</b></div>@endif</td>
    <td data-label="Last update" class="muted">{{ $t->last_reply_at?->diffForHumans() ?? $t->created_at->diffForHumans() }}@if ($t->last_reply_by)<div class="small">{{ $t->last_reply_by === 'staff' ? 'Reply from us' : 'Your message' }}</div>@endif</td>
</tr>
@endforeach</tbody></table>
{{ $tickets->links() }}
@endif
</x-card>
@endsection
