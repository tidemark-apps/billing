@extends('layouts.app', ['area' => 'portal'])
@section('title', $ticket->number.' · '.$ticket->subject)
@section('content')
<x-page-head :title="$ticket->subject" :back="route('portal.tickets.index')" back-label="Tickets">
    @if (! $ticket->is_locked && ! $ticket->isClosed())
    <form method="POST" action="{{ route('portal.tickets.close', $ticket->uuid) }}" data-confirm="Close this ticket? You can reopen it later by replying." data-confirm-label="Close ticket">@csrf<button class="btn" type="submit"><x-icon name="circle-check" :size="15" /> Mark as solved</button></form>
    @endif
</x-page-head>
<section class="card sumhead" aria-label="Ticket summary">
    <div><span class="k">Status</span><span class="v"><x-support-ticket-status :ticket="$ticket" /></span><span class="s">{{ $ticket->number }}</span></div>
    <div><span class="k">Department</span><span class="v">{{ $ticket->department?->name ?? 'Support' }}</span><span class="s">@if ($ticket->service)<a href="{{ route('portal.services.show', $ticket->service) }}">{{ $ticket->service->title() }}</a>@else No service linked @endif</span></div>
    <div><span class="k">Opened</span><span class="v">{{ $ticket->created_at->format('j M Y') }}</span><span class="s">{{ $ticket->created_at->format('H:i') }}</span></div>
    <div><span class="k">{{ $ticket->closed_at ? 'Closed' : 'Last update' }}</span><span class="v">{{ ($ticket->closed_at ?? $ticket->last_reply_at ?? $ticket->created_at)->diffForHumans() }}</span><span class="s">{{ $ticket->last_reply_by === 'staff' ? 'Reply from us' : 'Your message' }}</span></div>
</section>
@if ($ticket->status === \App\Enums\TicketStatus::Answered && ! $ticket->is_locked)<div class="notice good mb-16"><x-icon name="circle-check" :size="16" /><div>We have replied and are waiting to hear from you.</div></div>@endif
@if ($ticket->is_locked)<div class="notice warn mb-16"><x-icon name="lock" :size="16" /><div><b>This ticket is locked.</b> It can no longer be replied to or closed. Open a new ticket if you still need help.</div></div>
@elseif ($ticket->isClosed())<div class="notice mb-16"><x-icon name="info" :size="16" /><div><b>This ticket is closed.</b> Send a reply below if you still need help and we will reopen it.</div></div>@endif

<section class="card" aria-label="Conversation">
<ol class="thread">
@foreach ($messages as $m)
@php($staff = $m->author_type === 'staff')
<li class="msg {{ $staff ? 'staff' : 'customer' }}" id="m{{ $m->id }}">
    <div class="msg-h">
        <b>{{ $staff ? (\Illuminate\Support\Str::before(trim((string) $m->author_name), ' ') ?: ($ticket->department?->name ?? 'Support')).' from Support' : ($m->user_id === auth()->id() ? 'You' : ($m->author_name ?: 'You')) }}</b>
        <time class="muted small" datetime="{{ $m->created_at->toIso8601String() }}">{{ $m->created_at->format('j M Y H:i') }}</time>
    </div>
    <div class="msg-body">{{ $m->body }}</div>
    @if ($m->attachments->isNotEmpty())
    <div class="row wrap mt-12">
        @foreach ($m->attachments as $a)<a class="btn sm" href="{{ route('portal.tickets.attachments.download', [$ticket->uuid, $a->uuid]) }}"><x-icon name="download" :size="14" /> {{ $a->original_name }} <span class="muted">({{ \Illuminate\Support\Number::fileSize($a->size) }})</span></a>@endforeach
    </div>
    @endif
</li>
@endforeach
</ol>
</section>

@unless ($ticket->is_locked)
<form method="POST" action="{{ route('portal.tickets.reply', $ticket->uuid) }}" enctype="multipart/form-data" class="mt-16" data-validate>@csrf
<x-card title="{{ $ticket->isClosed() ? 'Reopen with a reply' : 'Reply' }}">
    <div class="stack">
        <x-field name="body" label="Your message" type="textarea" rows="5" aria-required="true" />
        @include('portal.tickets._files', ['limits' => $limits])
        <div class="row"><button class="btn primary" type="submit"><x-icon name="send" :size="15" /> {{ $ticket->isClosed() ? 'Reopen and send' : 'Send reply' }}</button></div>
    </div>
</x-card>
</form>
@endunless
@if ($fields->isNotEmpty())
<div class="mt-16"><x-card title="Details you gave us">
    <dl class="kv wide m-0">
        @foreach ($fields as $f)
            @php($v = $ticket->custom_fields[$f->key])
            <dt>{{ $f->label }}</dt><dd>{{ $f->type === 'checkbox' ? ($v ? 'Yes' : 'No') : (is_scalar($v) ? $v : '') }}</dd>
        @endforeach
    </dl>
</x-card></div>
@endif
@endsection
