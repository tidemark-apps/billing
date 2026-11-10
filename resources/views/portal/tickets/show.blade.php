@extends('layouts.app', ['area' => 'portal'])
@section('title', $ticket->number.' · '.$ticket->subject)
@section('content')
<x-page-head :title="$ticket->subject" :back="route('portal.tickets.index')" back-label="Tickets">
    <x-status :status="$ticket->status" />@if ($ticket->is_locked) <span class="pill outline"><x-icon name="lock" :size="12" /> Locked</span>@endif
    @if (! $ticket->is_locked && ! $ticket->isClosed())
    <form method="POST" action="{{ route('portal.tickets.close', $ticket->uuid) }}" onsubmit="return confirm('Close this ticket?')">@csrf<button class="btn" type="submit">Close ticket</button></form>
    @endif
</x-page-head>
@if ($ticket->status === \App\Enums\TicketStatus::Answered)<div class="notice good"><x-icon name="circle-check" :size="16" /><div>We have replied and are waiting to hear from you.</div></div>@endif
@if ($ticket->is_locked)<div class="notice warn"><x-icon name="lock" :size="16" /><div>This ticket is locked, so it can no longer be replied to or closed. Please open a new ticket if you still need help.</div></div>@endif

<x-card title="Details">
    <dl class="kv wide">
        <dt>Ticket</dt><dd>{{ $ticket->number }}</dd>
        <dt>Department</dt><dd>{{ $ticket->department?->name ?? '—' }}</dd>
        @if ($ticket->service)<dt>Service</dt><dd><a href="{{ route('portal.services.show', $ticket->service) }}">{{ $ticket->service->title() }}</a></dd>@endif
        <dt>Opened</dt><dd>{{ $ticket->created_at->format('j M Y H:i') }}</dd>
        @if ($ticket->closed_at)<dt>Closed</dt><dd>{{ $ticket->closed_at->format('j M Y H:i') }}</dd>@endif
        @foreach ($fields as $f)
            @php($v = $ticket->custom_fields[$f->key])
            <dt>{{ $f->label }}</dt><dd>{{ $f->type === 'checkbox' ? ($v ? 'Yes' : 'No') : (is_scalar($v) ? $v : '') }}</dd>
        @endforeach
    </dl>
</x-card>

@foreach ($messages as $m)
@php($staff = $m->author_type === 'staff')
<section class="card" style="margin-bottom:12px;@if ($staff) border-left:3px solid var(--brand) @endif">
    <div class="card-h"><h3>{{ $staff ? (\Illuminate\Support\Str::before(trim((string) $m->author_name), ' ') ?: ($ticket->department?->name ?? 'Support')).' · Support' : ($m->user_id === auth()->id() ? 'You' : ($m->author_name ?: 'You')) }}</h3><span class="muted small">{{ $m->created_at->format('j M Y H:i') }}</span></div>
    <div class="card-b">
        <div style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $m->body }}</div>
        @if ($m->attachments->isNotEmpty())
        <div class="row wrap" style="margin-top:12px">
            @foreach ($m->attachments as $a)<a class="btn sm" href="{{ route('portal.tickets.attachments.download', [$ticket->uuid, $a->uuid]) }}"><x-icon name="download" :size="14" /> {{ $a->original_name }} <span class="muted">({{ \Illuminate\Support\Number::fileSize($a->size) }})</span></a>@endforeach
        </div>
        @endif
    </div>
</section>
@endforeach

@unless ($ticket->is_locked)
<form method="POST" action="{{ route('portal.tickets.reply', $ticket->uuid) }}" enctype="multipart/form-data">@csrf
<x-card title="{{ $ticket->isClosed() ? 'Reopen with a reply' : 'Reply' }}">
    <div class="stack">
        @if ($ticket->isClosed())<p class="muted small">This ticket is closed. Replying will reopen it.</p>@endif
        <x-field name="body" label="Your reply" type="textarea" />
        @include('portal.tickets._files', ['limits' => $limits])
        <div><button class="btn primary" type="submit"><x-icon name="send" :size="15" /> Send reply</button></div>
    </div>
</x-card>
</form>
@endunless
@endsection
