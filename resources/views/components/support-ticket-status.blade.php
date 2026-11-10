@props(['ticket'])
{{-- Customer-facing ticket status: one pill, plain wording. Locked outranks the workflow status. --}}
@php
    $st = $ticket->status;
    [$label, $tone] = match (true) {
        $ticket->is_locked && $ticket->isClosed() => ['Closed', 'outline'],
        $ticket->is_locked => ['Locked', 'outline'],
        $st === \App\Enums\TicketStatus::Answered => ['We replied', 'brand'],
        $st === \App\Enums\TicketStatus::OnHold => ['On hold', 'outline'],
        $st === \App\Enums\TicketStatus::Closed => ['Closed', 'outline'],
        default => ['Waiting for us', 'warn'],
    };
@endphp
<span class="pill {{ $tone }}">@if ($ticket->is_locked && ! $ticket->isClosed())<x-icon name="lock" :size="11" /> @endif{{ $label }}</span>
