@extends('layouts.app', ['area' => 'admin'])
@php($editing = $department->exists)
@section('title', $editing ? 'Edit '.$department->name : 'Add department')
@section('content')
<x-page-head :title="$editing ? $department->name : 'Add department'" :back="route('admin.departments.index')" backLabel="Departments" />
<form method="POST" action="{{ $editing ? route('admin.departments.update', $department) : route('admin.departments.store') }}" class="stack">
    @csrf @if ($editing) @method('PUT') @endif
    <x-card title="Department">
        <div class="grid2">
            <x-field name="name" label="Name" :value="$department->name" required />
            <x-field name="slug" label="Slug" :value="$department->slug" help="Used in links. Leave blank to make it from the name." />
            <div class="span2"><x-field name="description" label="Description" type="textarea" :value="$department->description" help="Shown to customers choosing a department." /></div>
            <x-field name="sort" label="Sort order" type="number" min="0" :value="$department->sort ?? 0" help="Lower numbers come first." />
        </div>
        <div class="grid3" style="margin-top:16px">
            <x-field name="is_public" type="checkbox" label="Listed for customers" :value="$department->is_public" help="Off makes it unlisted: not offered on the customer's new-ticket form, but staff can still use it and email still reaches it." />
            <x-field name="is_active" type="checkbox" label="Active" :value="$department->is_active" help="Inactive departments accept no new tickets." />
            <x-field name="auto_reply" type="checkbox" label="Acknowledge new tickets by email" :value="$department->auto_reply" />
        </div>
    </x-card>
    <x-card title="Email address">
        <x-field name="email" type="email" label="Department email" :value="$department->email" placeholder="support@example.com" help="The one address mail for this department arrives at. Replies to customers use it as the Reply-To. Each address can belong to only one department." />
        <p class="help" style="margin-top:12px">Get that mail into tickets either by pulling it from a mailbox over IMAP (below), or by having your mail server pipe incoming messages to <code>php artisan tickets:pipe</code>, which needs no settings here.</p>
    </x-card>
    <x-card title="Staff">
        @if ($people->isEmpty())<p class="muted">No active staff yet.</p>@else
        <div class="stack sm">
            @foreach ($people as $p)
                <label class="check"><input type="checkbox" name="staff[]" value="{{ $p->id }}" @checked(in_array($p->id, array_map('intval', (array) old('staff', $selected)), true))> {{ $p->name }} <span class="muted">{{ $p->email }} @if ($p->isAdmin()) · Administrator @endif</span></label>
            @endforeach
        </div>@endif
        <p class="help" style="margin-top:12px">Members see this department's tickets and can be assigned to them. Staff in no department only see tickets assigned directly to them; admins see everything.</p>
    </x-card>
    <x-card title="IMAP mailbox">
        <div class="stack">
            <x-field name="imap_enabled" type="checkbox" label="Pull mail from an IMAP mailbox" :value="$department->imap_enabled" help="Checked every few minutes by the scheduler. New mail becomes tickets; replies attach to their ticket." />
            <div class="grid2">
                <x-field name="imap_host" label="Host" :value="$department->imap_host" placeholder="imap.example.com" />
                <x-field name="imap_port" label="Port" type="number" :value="$department->imap_port" />
                <x-field name="imap_encryption" label="Encryption" type="select" :options="['ssl' => 'SSL/TLS (993)', 'starttls' => 'STARTTLS (143)', 'none' => 'None']" :value="$department->imap_encryption" />
                <x-field name="imap_folder" label="Folder" :value="$department->imap_folder" />
                <x-field name="imap_username" label="Username" :value="$department->imap_username" autocomplete="off" />
                <x-field name="imap_password" label="Password" type="password" autocomplete="new-password" :placeholder="$editing && $department->imap_password ? '•••••••• (saved, leave blank to keep)' : null" />
                <x-field name="imap_after" label="After importing a message" type="select" :options="['seen' => 'Mark it as read', 'delete' => 'Delete it from the mailbox']" :value="$department->imap_after" />
            </div>
            <x-field name="imap_validate_cert" type="checkbox" label="Validate the server's certificate" :value="$department->imap_validate_cert" help="Turn off only for self-signed certificates." />
            @if ($editing)
                @if ($department->imap_checked_at)<p class="muted small">Last checked {{ $department->imap_checked_at->diffForHumans() }}.</p>@else<p class="muted small">Not checked yet.</p>@endif
                @if ($department->imap_error)<div class="notice danger"><x-icon name="triangle-alert" :size="16" /><div>Last error: {{ $department->imap_error }}</div></div>@endif
            @endif
        </div>
        <x-slot:footer><span>{{ $editing ? 'Tests what is in the form now (a blank password uses the saved one). Nothing is saved until you press Save.' : 'You can test the connection after saving.' }}</span>@if ($editing)<button class="btn sm" type="submit" formaction="{{ route('admin.departments.test', $department) }}" formmethod="post" data-no-lock>Test connection</button>@endif</x-slot:footer>
    </x-card>
    <div class="row"><button class="btn primary" type="submit">{{ $editing ? 'Save changes' : 'Add department' }}</button><a class="btn" href="{{ route('admin.departments.index') }}">Cancel</a></div>
</form>

@endsection
