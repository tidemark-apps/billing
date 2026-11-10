@extends('layouts.app', ['area' => 'admin'])
@section('title', $ticket->number.' '.$ticket->subject)
@push('head') @include('admin.partials.filters-style') @endpush
@php
    $me = auth()->user();
    $answers = $ticket->custom_fields ?? [];
    $statusOptions = collect(\App\Enums\TicketStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
    $priorityOptions = collect(\App\Enums\TicketPriority::cases())->mapWithKeys(fn ($p) => [$p->value => $p->label()])->all();
    $needs = $ticket->status->awaitsStaff();
    $since = $ticket->last_reply_at ?? $ticket->created_at;
    $noteMode = $errors->has('note') || old('note') !== null;
    $high = in_array($ticket->priority->value, ['high', 'urgent'], true);
@endphp
@section('content')
<x-page-head :title="$ticket->subject" :sub="$ticket->number" :back="route('admin.tickets.index')" backLabel="Tickets">
    @if ($ticket->assigned_to !== $me->id)
        <form method="POST" action="{{ route('admin.tickets.claim', $ticket) }}">@csrf<button class="btn" type="submit"><x-icon name="user-plus" :size="15" /> Assign to me</button></form>
    @endif
    @if ($ticket->isClosed())
        <form method="POST" action="{{ route('admin.tickets.status', $ticket) }}">@csrf<input type="hidden" name="status" value="open"><button class="btn" type="submit"><x-icon name="rotate-ccw" :size="15" /> Reopen</button></form>
    @else
        <form method="POST" action="{{ route('admin.tickets.status', $ticket) }}" data-confirm="Close {{ $ticket->number }}? The customer can still reply to reopen it." data-confirm-label="Close ticket">@csrf<input type="hidden" name="status" value="closed"><button class="btn" type="submit"><x-icon name="circle-check" :size="15" /> Close</button></form>
    @endif
    @if ($ticket->is_locked)
        <form method="POST" action="{{ route('admin.tickets.unlock', $ticket) }}">@csrf<button class="btn" type="submit"><x-icon name="lock" :size="15" /> Unlock</button></form>
    @else
        <form method="POST" action="{{ route('admin.tickets.lock', $ticket) }}" data-confirm="Lock this ticket? The customer will no longer be able to reply." data-confirm-label="Lock">@csrf<button class="btn" type="submit"><x-icon name="lock" :size="15" /> Lock</button></form>
    @endif
    @if ($me->isAdmin())
    <x-menu label="More">
        <form method="POST" action="{{ route('admin.tickets.destroy', $ticket) }}" data-confirm="Delete {{ $ticket->number }} and all its messages and files? This cannot be undone." data-confirm-label="Delete ticket" data-danger>@csrf @method('DELETE')<button class="item danger" type="submit" role="menuitem"><span>Delete ticket</span></button></form>
    </x-menu>
    @endif
</x-page-head>

<section class="card sumhead" aria-label="Ticket summary">
    <div><span class="k">Status</span><span class="v"><x-status :status="$ticket->status" /></span><span class="s">@if ($ticket->is_locked)Locked: the customer cannot reply @else Opened {{ $ticket->created_at->format('j M Y H:i') }} @endif</span></div>
    <div><span class="k">Priority</span><span class="v {{ $ticket->priority->value === 'urgent' ? 'bad-text' : '' }}">{{ $ticket->priority->label() }}</span><span class="s">{{ $high ? 'Handle first' : 'Normal queue' }}</span></div>
    <div><span class="k">{{ $needs ? 'Waiting for a reply' : 'Last activity' }}</span><span class="v {{ $needs && $since->lt(now()->subDay()) ? 'bad-text' : '' }}">{{ $since->diffForHumans(null, true) }}</span><span class="s">{{ $needs ? 'Since '.$since->format('j M H:i') : 'By '.($ticket->last_reply_by ?? 'nobody yet') }}</span></div>
    <div><span class="k">Assigned to</span><span class="v">{{ $ticket->assignee?->name ?? 'Nobody' }}</span><span class="s">{{ $ticket->department?->name ?? 'No department' }} · via {{ $ticket->source }}</span></div>
</section>

<div class="split-layout">
<div class="stack">
    <section class="card" aria-label="Conversation">
        <ol class="thread">
        @foreach ($messages as $m)
            @if ($m->isEvent())
                <li class="event" id="m{{ $m->id }}"><span>{{ $m->body }}@if ($m->author_name) · {{ $m->author_name }}@endif</span><time datetime="{{ $m->created_at->toIso8601String() }}">{{ $m->created_at->format('j M H:i') }}</time></li>
            @else
                @php($kind = $m->isNote() ? 'note' : ($m->fromStaff() ? 'staff' : 'customer'))
                @php($who = $m->author_name ?: ($m->fromStaff() ? 'Staff' : $ticket->requester_name))
                <li class="msg {{ $kind }}" id="m{{ $m->id }}">
                    <div class="msg-h">
                        <span class="avatar sm" aria-hidden="true">@if ($kind === 'note')<x-icon name="lock" :size="12" />@else{{ mb_strtoupper(mb_substr($who, 0, 1)) }}@endif</span>
                        <b>{{ $who }}</b>
                        @if ($kind === 'note')<span class="pill warn">Internal note, staff only</span>
                        @else<span class="muted small">{{ $kind === 'staff' ? 'replied to the customer' : 'Customer' }}</span>@endif
                        @if ($m->source === 'email')<span class="muted small"><x-icon name="mail" :size="12" /> via email</span>@endif
                        <time class="muted small" datetime="{{ $m->created_at->toIso8601String() }}" title="{{ $m->created_at->format('j M Y H:i') }}">{{ $m->created_at->diffForHumans() }}</time>
                    </div>
                    <div class="msg-body">{{ $m->body }}</div>
                    @if ($m->attachments->isNotEmpty())
                        <div class="row wrap mt-12">
                            @foreach ($m->attachments as $a)
                                <a class="btn sm" href="{{ route('admin.tickets.attachments.download', [$ticket, $a]) }}"><x-icon name="download" :size="13" /> {{ $a->original_name }} <span class="muted">{{ \Illuminate\Support\Number::fileSize($a->size) }}</span></a>
                            @endforeach
                        </div>
                    @endif
                </li>
            @endif
        @endforeach
        </ol>
    </section>

    <section class="card composer {{ $noteMode ? 'is-note' : '' }}" id="composer" data-composer aria-label="Reply to ticket">
        <div class="tabs" role="tablist" aria-label="Reply type">
            <button type="button" role="tab" id="mode-reply" aria-controls="panel-reply" aria-selected="{{ $noteMode ? 'false' : 'true' }}" data-mode="reply" class="{{ $noteMode ? "" : "on" }}"><x-icon name="send" :size="14" /> Reply to customer</button>
            <button type="button" role="tab" id="mode-note" aria-controls="panel-note" aria-selected="{{ $noteMode ? 'true' : 'false' }}" data-mode="note" class="{{ $noteMode ? "on" : "" }}"><x-icon name="lock" :size="14" /> Internal note</button>
        </div>
        <form method="POST" action="{{ route('admin.tickets.reply', $ticket) }}" enctype="multipart/form-data" class="stack panel" id="panel-reply" role="tabpanel" aria-labelledby="mode-reply" @if ($noteMode) hidden @endif>
            @csrf
            @if ($ticket->is_locked)<p class="help m-0">This ticket is locked: the customer cannot reply, but you still can.</p>@endif
            <p class="help m-0"><x-icon name="mail" :size="13" /> The customer sees this. We email it to {{ $ticket->requester_email }}.</p>
            <x-field name="body" label="Reply" type="textarea" rows="6" id="reply-body" />
            @include('admin.tickets._upload', ['maxFiles' => $maxFiles, 'maxMb' => $maxMb, 'allowed' => $allowed])
            <div class="row wrap spread">
                <x-field name="status" label="Then set status to" type="select" :options="['answered' => 'Answered', 'on_hold' => 'On hold', 'closed' => 'Closed']" value="answered" id="reply-status" />
                <button class="btn primary" type="submit"><x-icon name="send" :size="14" /> Send reply</button>
            </div>
        </form>
        <form method="POST" action="{{ route('admin.tickets.note', $ticket) }}" enctype="multipart/form-data" class="stack panel" id="panel-note" role="tabpanel" aria-labelledby="mode-note" @unless ($noteMode) hidden @endunless>
            @csrf
            <p class="help m-0"><x-icon name="lock" :size="13" /> Only staff see this. The customer is not told.</p>
            <x-field name="note" label="Internal note" type="textarea" rows="6" id="note-body" />
            @include('admin.tickets._upload', ['maxFiles' => $maxFiles, 'maxMb' => $maxMb, 'allowed' => $allowed, 'id' => 'note-files'])
            <div><button class="btn primary" type="submit"><x-icon name="lock" :size="14" /> Add internal note</button></div>
        </form>
    </section>
</div>

<aside aria-label="Ticket details">
    <section class="card setting">
        <div class="setting-h"><h2>Details</h2><p>Who it is from and where it sits. Changes save as you pick them.</p></div>
        <div class="setting-b">
            <dl class="kv m-0">
                <dt>Requester</dt><dd>{{ $ticket->requester_name }}<div class="muted small break-all"><a href="mailto:{{ $ticket->requester_email }}">{{ $ticket->requester_email }}</a></div></dd>
                <dt>Client</dt><dd>@if ($ticket->client)<a href="{{ route('admin.clients.show', $ticket->client) }}">{{ $ticket->client->displayName() }}</a>@else<span class="muted">No account (email sender)</span>@endif</dd>
                <dt>Service</dt><dd>@if ($ticket->service)<a href="{{ route('admin.services.show', $ticket->service) }}">{{ $ticket->service->title() }}</a>@else<span class="muted">None</span>@endif</dd>
            </dl>
            <form method="POST" action="{{ route('admin.tickets.status', $ticket) }}">@csrf
                <label for="status-select"><span>Status</span><select id="status-select" name="status" data-autosubmit>@foreach ($statusOptions as $k => $v)<option value="{{ $k }}" @selected($ticket->status->value === $k)>{{ $v }}</option>@endforeach</select></label>
                <noscript><button class="btn sm mt-8" type="submit">Save</button></noscript>
            </form>
            <form method="POST" action="{{ route('admin.tickets.priority', $ticket) }}">@csrf
                <label for="priority-select"><span>Priority</span><select id="priority-select" name="priority" data-autosubmit>@foreach ($priorityOptions as $k => $v)<option value="{{ $k }}" @selected($ticket->priority->value === $k)>{{ $v }}</option>@endforeach</select></label>
                <noscript><button class="btn sm mt-8" type="submit">Save</button></noscript>
            </form>
            <form method="POST" action="{{ route('admin.tickets.assign', $ticket) }}">@csrf
                <label for="assign-user"><span>Assigned to</span><select id="assign-user" name="user_id" data-autosubmit><option value="">Nobody</option>@foreach ($assignable as $u)<option value="{{ $u->id }}" @selected($ticket->assigned_to === $u->id)>{{ $u->name }}{{ $u->id === $me->id ? ' (you)' : '' }}</option>@endforeach</select></label>
                <noscript><button class="btn sm mt-8" type="submit">Save</button></noscript>
            </form>
            <form method="POST" action="{{ route('admin.tickets.transfer', $ticket) }}" @if (! $me->isAdmin()) data-confirm="Move this ticket to another department? It may leave your queue." data-confirm-label="Move ticket" @endif>@csrf
                <label for="transfer-dept"><span>Department</span><select id="transfer-dept" name="department_id" data-autosubmit>@foreach ($departments as $d)<option value="{{ $d->id }}" @selected($ticket->department_id === $d->id)>{{ $d->name }}</option>@endforeach</select></label>
                <noscript><button class="btn sm mt-8" type="submit">Save</button></noscript>
            </form>
        </div>
    </section>

    @if ($fields->where('staff_only', false)->contains(fn ($f) => array_key_exists($f->key, $answers)) || $orphans->isNotEmpty())
    <section class="card setting">
        <div class="setting-h"><h2>Form answers</h2><p>What the customer filled in when they opened the ticket.</p></div>
        <div class="setting-b">
            <dl class="kv m-0">
            @foreach ($fields->where('staff_only', false) as $f)
                @continue(! array_key_exists($f->key, $answers))
                <dt>{{ $f->label }}</dt><dd>{{ $f->type === 'checkbox' ? ($answers[$f->key] ? 'Yes' : 'No') : $answers[$f->key] }}</dd>
            @endforeach
            @foreach ($orphans as $label => $value)<dt>{{ $label }}</dt><dd>{{ is_bool($value) ? ($value ? 'Yes' : 'No') : (is_scalar($value) ? $value : json_encode($value)) }}</dd>@endforeach
            </dl>
        </div>
    </section>
    @endif

    @if ($fields->where('staff_only', true)->isNotEmpty())
    <x-setting title="Staff-only details" description="Notes for your team. The customer never sees these." :action="route('admin.tickets.fields', $ticket)" method="PUT">
        @include('admin.tickets._fields', ['fields' => $fields->where('staff_only', true), 'values' => old('custom', $answers)])
        <x-slot:footer><button class="btn primary sm" type="submit">Save details</button></x-slot:footer>
    </x-setting>
    @endif
</aside>
</div>
@endsection
