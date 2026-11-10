{!! \App\Services\Support\InboundEmail::MARKER !!}

@if ($ticket->is_locked)
Your request ({!! $ticket->number !!}) has been updated. This ticket is locked, so replies are closed.
@else
Your request ({!! $ticket->number !!}) has been updated. To add additional comments, reply to this email.
@endif
@foreach ($thread as $m)

----------------------------------------------

{!! $m->author_name ?: $m->user?->name ?: 'Customer' !!}@if ($m->fromStaff()) ({!! $company !!})@endif

{!! $m->created_at->format('M j, Y, H:i T') !!}

{!! trim($m->body) !!}
@if ($loop->first && $attachments > 0)

{{ $attachments }} attachment{{ $attachments === 1 ? '' : 's' }}{!! $ticketUrl ? ': open the ticket to download '.($attachments === 1 ? 'it' : 'them').'.' : ' not shown here.' !!}
@endif
@endforeach

----------------------------------------------
@if ($ticketUrl)
View it online: {!! $ticketUrl !!}
@endif
This email is a service from {!! $company !!}.
