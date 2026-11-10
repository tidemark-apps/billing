@extends('layouts.app', ['area' => 'admin'])
@section('title', $ticket->number.' '.$ticket->subject)
@push('head') @include('admin.partials.filters-style') @endpush
@php
    $me = auth()->user();
    $answers = $ticket->custom_fields ?? [];
    $statusOptions = collect(\App\Enums\TicketStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
@endphp
@section('content')
<x-page-head :title="$ticket->number.' · '.$ticket->subject" :back="route('admin.tickets.index')" backLabel="Tickets">
    @if ($me->isAdmin())<form method="POST" action="{{ route('admin.tickets.destroy', $ticket) }}" data-confirm="Delete {{ $ticket->number }} and all its messages and files? This cannot be undone." data-confirm-label="Delete" data-danger>@csrf @method('DELETE')<button class="btn danger" type="submit">Delete</button></form>@endif
</x-page-head>
<div class="row wrap" style="margin-bottom:16px">
    <x-status :status="$ticket->status" /><x-status :status="$ticket->priority" />
    @if ($ticket->is_locked)<span class="pill warn"><x-icon name="lock" :size="12" /> Locked</span>@endif
    <span class="muted small">Opened {{ $ticket->created_at->format('j M Y H:i') }} via {{ $ticket->source }}</span>
</div>
<div class="split-layout">
<div class="stack">
    <x-card title="Details">
        <dl class="kv wide">
            <dt>Requester</dt><dd>{{ $ticket->requester_name }} <span class="muted">&lt;{{ $ticket->requester_email }}&gt;</span></dd>
            <dt>Client</dt><dd>@if ($ticket->client)<a href="{{ route('admin.clients.show', $ticket->client) }}">{{ $ticket->client->displayName() }}</a>@else<span class="muted">No account (email sender)</span>@endif</dd>
            <dt>Department</dt><dd>{{ $ticket->department?->name ?? '—' }}</dd>
            <dt>Service</dt><dd>@if ($ticket->service)<a href="{{ route('admin.services.show', $ticket->service) }}">{{ $ticket->service->title() }}</a>@else<span class="muted">None</span>@endif</dd>
            <dt>Assigned to</dt><dd>{{ $ticket->assignee?->name ?? 'Nobody' }}</dd>
            @foreach ($fields->where('staff_only', false) as $f)
                @continue(! array_key_exists($f->key, $answers))
                <dt>{{ $f->label }}</dt><dd>{{ $f->type === 'checkbox' ? ($answers[$f->key] ? 'Yes' : 'No') : $answers[$f->key] }}</dd>
            @endforeach
            @foreach ($orphans as $label => $value)<dt>{{ $label }}</dt><dd>{{ is_bool($value) ? ($value ? 'Yes' : 'No') : (is_scalar($value) ? $value : json_encode($value)) }}</dd>@endforeach
        </dl>
        @if ($fields->where('staff_only', true)->isNotEmpty())
        <form method="POST" action="{{ route('admin.tickets.fields', $ticket) }}" class="stack" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border)">
            @csrf @method('PUT')
            <b>Staff-only details</b>
            <div class="grid2">@include('admin.tickets._fields', ['fields' => $fields->where('staff_only', true), 'values' => old('custom', $answers)])</div>
            <div><button class="btn sm" type="submit">Save details</button></div>
        </form>
        @endif
    </x-card>

    <x-card title="Conversation">
        <div class="stack">
        @foreach ($messages as $m)
            @if ($m->isEvent())
                <div class="muted small" style="text-align:center">{{ $m->body }}@if ($m->author_name) · {{ $m->author_name }}@endif · {{ $m->created_at->format('j M H:i') }}</div>
            @else
                <article class="notice {{ $m->isNote() ? 'warn' : ($m->fromStaff() ? 'good' : '') }}" style="display:block" id="m{{ $m->id }}">
                    <div class="row spread wrap" style="margin-bottom:8px">
                        <div class="row wrap"><b>{{ $m->author_name ?: ($m->fromStaff() ? 'Staff' : $ticket->requester_name) }}</b>
                            @if ($m->isNote())<span class="pill warn">INTERNAL NOTE · staff only</span>@else<span class="pill {{ $m->fromStaff() ? 'good' : 'outline' }}">{{ $m->fromStaff() ? 'Staff' : 'Customer' }}</span>@endif
                            @if ($m->source === 'email')<span class="pill outline"><x-icon name="mail" :size="12" /> email</span>@endif</div>
                        <span class="muted small">{{ $m->created_at->format('j M Y H:i') }}</span>
                    </div>
                    <div style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $m->body }}</div>
                    @if ($m->attachments->isNotEmpty())
                        <div class="row wrap" style="margin-top:10px">
                            @foreach ($m->attachments as $a)
                                <a class="btn sm" href="{{ route('admin.tickets.attachments.download', [$ticket, $a]) }}"><x-icon name="download" :size="13" /> {{ $a->original_name }} <span class="muted">{{ \Illuminate\Support\Number::fileSize($a->size) }}</span></a>
                            @endforeach
                        </div>
                    @endif
                </article>
            @endif
        @endforeach
        </div>
    </x-card>

    <x-card title="Reply to customer">
        <form method="POST" action="{{ route('admin.tickets.reply', $ticket) }}" enctype="multipart/form-data" class="stack">
            @csrf
            @if ($ticket->is_locked)<p class="muted small">This ticket is locked: the customer cannot reply, but you still can.</p>@endif
            <x-field name="body" label="Reply" type="textarea" rows="7" id="reply-body" />
            @include('admin.tickets._upload', ['maxFiles' => $maxFiles, 'maxMb' => $maxMb, 'allowed' => $allowed])
            <div class="row wrap">
                <x-field name="status" label="Set status after sending" type="select" :options="['answered' => 'Answered', 'on_hold' => 'On hold', 'closed' => 'Closed']" value="answered" id="reply-status" />
                <button class="btn primary" type="submit">Send reply</button>
            </div>
            <p class="help">The customer is emailed this reply. Internal notes are never sent.</p>
        </form>
    </x-card>

    <x-card title="Add internal note">
        <form method="POST" action="{{ route('admin.tickets.note', $ticket) }}" enctype="multipart/form-data" class="stack">
            @csrf
            <x-field name="note" label="Note (staff only)" type="textarea" rows="4" id="note-body" />
            @include('admin.tickets._upload', ['maxFiles' => $maxFiles, 'maxMb' => $maxMb, 'allowed' => $allowed, 'id' => 'note-files'])
            <div><button class="btn" type="submit">Add note</button></div>
            <p class="help">Only staff can see notes. The customer is not told.</p>
        </form>
    </x-card>
</div>
<aside class="stack-actions">
    <x-card title="Assignment">
        @if ($ticket->assigned_to !== $me->id)
            <form method="POST" action="{{ route('admin.tickets.claim', $ticket) }}">@csrf<button class="btn primary" type="submit">Assign to me</button></form>
        @endif
        <form method="POST" action="{{ route('admin.tickets.assign', $ticket) }}" class="stack sm">@csrf
            <x-field name="user_id" label="Assign to" type="select" :options="['' => 'Nobody (unassign)'] + $assignable->pluck('name', 'id')->all()" :value="$ticket->assigned_to" id="assign-user" />
            <button class="btn" type="submit">Save assignment</button>
        </form>
        @if ($ticket->assigned_to)<form method="POST" action="{{ route('admin.tickets.assign', $ticket) }}">@csrf<input type="hidden" name="user_id" value=""><button class="btn ghost" type="submit">Unassign</button></form>@endif
    </x-card>
    <x-card title="Status">
        <form method="POST" action="{{ route('admin.tickets.status', $ticket) }}" class="stack sm">@csrf
            <x-field name="status" label="Status" type="select" :options="$statusOptions" :value="$ticket->status->value" id="status-select" />
            <button class="btn" type="submit">Change status</button>
        </form>
        @if ($ticket->isClosed())
            <form method="POST" action="{{ route('admin.tickets.status', $ticket) }}">@csrf<input type="hidden" name="status" value="open"><button class="btn" type="submit">Reopen</button></form>
        @else
            <form method="POST" action="{{ route('admin.tickets.status', $ticket) }}">@csrf<input type="hidden" name="status" value="closed"><button class="btn" type="submit">Close ticket</button></form>
        @endif
        @if ($ticket->is_locked)
            <form method="POST" action="{{ route('admin.tickets.unlock', $ticket) }}">@csrf<button class="btn" type="submit"><x-icon name="lock" :size="14" /> Unlock</button></form>
        @else
            <form method="POST" action="{{ route('admin.tickets.lock', $ticket) }}" data-confirm="Lock this ticket? The customer will no longer be able to reply." data-confirm-label="Lock">@csrf<button class="btn" type="submit"><x-icon name="lock" :size="14" /> Lock replies</button></form>
        @endif
    </x-card>
    <x-card title="Priority">
        <form method="POST" action="{{ route('admin.tickets.priority', $ticket) }}" class="stack sm">@csrf
            <x-field name="priority" label="Priority" type="select" :options="collect(\App\Enums\TicketPriority::cases())->mapWithKeys(fn ($p) => [$p->value => $p->label()])->all()" :value="$ticket->priority->value" id="priority-select" />
            <button class="btn" type="submit">Set priority</button>
        </form>
    </x-card>
    <x-card title="Department">
        <form method="POST" action="{{ route('admin.tickets.transfer', $ticket) }}" class="stack sm">@csrf
            <x-field name="department_id" label="Move to" type="select" :options="$departments->pluck('name', 'id')->all()" :value="$ticket->department_id" id="transfer-dept" />
            <button class="btn" type="submit">Move ticket</button>
        </form>
    </x-card>
</aside>
</div>
@endsection
