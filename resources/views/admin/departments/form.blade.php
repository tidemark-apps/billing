@extends('layouts.app', ['area' => 'admin'])
@php($editing = $department->exists)
@section('title', $editing ? 'Edit '.$department->name : 'Add department')
@section('content')
<x-page-head :title="$editing ? $department->name : 'Add department'" :sub="$editing ? 'Settings for this department.' : 'A queue that tickets are sorted into.'" :back="route('admin.departments.index')" backLabel="Departments" />
@php($isPublic = (bool) old('is_public', $department->exists ? $department->is_public : true))
<form method="POST" action="{{ $editing ? route('admin.departments.update', $department) : route('admin.departments.store') }}" class="card setting form-narrow" data-validate>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="setting-h"><h2>Department</h2><p>Who it is for, who works in it and how mail reaches it.</p></div>
    <div class="setting-b">
        <x-field name="name" label="Name" :value="$department->name" aria-required="true" />
        <x-field name="description" label="Description (optional)" type="textarea" :value="$department->description" help="Shown to customers under the department name when they choose one." />
        <div class="grid2">
            <x-field name="slug" label="Link name (optional)" :value="$department->slug" help="Used in links. Made from the name if blank." />
            <x-field name="sort" label="Sort order" type="number" min="0" :value="$department->sort ?? 0" help="Lower numbers come first." />
        </div>

        <h3>Who can use it</h3>
        <div class="choice-group" role="radiogroup" aria-label="Visibility to customers">
            <label class="choice"><input type="radio" name="is_public" value="1" @checked($isPublic)><span><b>Listed</b><span class="help">Customers can choose it when they open a ticket in the portal.</span></span></label>
            <label class="choice"><input type="radio" name="is_public" value="0" @checked(! $isPublic)><span><b>Unlisted</b><span class="help">Hidden from the customer's new ticket form. Staff can still open tickets in it, and email sent to its address still arrives.</span></span></label>
        </div>
        <x-field name="is_active" type="checkbox" label="Active" :value="$department->exists ? $department->is_active : true" help="Inactive departments accept no new tickets. Existing ones stay readable." />

        <h3>Staff</h3>
        @if ($people->isEmpty())<p class="muted m-0">No active staff yet.</p>@else
        <div class="grid2">
            @foreach ($people as $p)
                <label class="check"><input type="checkbox" name="staff[]" value="{{ $p->id }}" @checked(in_array($p->id, array_map('intval', (array) old('staff', $selected)), true))> <span>{{ $p->name }} <span class="muted">{{ $p->email }}@if ($p->isAdmin()) · Administrator @endif</span></span></label>
            @endforeach
        </div>@endif
        <p class="help m-0">Members see this department's tickets and can be assigned to them. Staff in no department only see tickets assigned directly to them. Admins see everything.</p>

        <h3>Email</h3>
        <x-field name="email" type="email" label="Department email (optional)" :value="$department->email" placeholder="support@example.com" help="Mail sent to this address becomes tickets here, and replies use it as the Reply-To. One address can belong to one department only." />
        <x-field name="auto_reply" type="checkbox" label="Acknowledge new tickets by email" :value="$department->auto_reply" help="Sends the customer a short confirmation with their ticket number." />
        <p class="help m-0">To get mail into tickets, either have your mail server pipe messages to <code>php artisan tickets:pipe</code> (nothing to set up here), or pull from a mailbox below.</p>

        <h3>Mailbox (IMAP)</h3>
        <x-field name="imap_enabled" type="checkbox" label="Pull mail from a mailbox" :value="$department->imap_enabled" data-imap-toggle help="We check every few minutes. New mail becomes tickets and replies attach to their ticket." />
        <div class="stack" data-imap-body>
            <div class="grid2">
                <x-field name="imap_host" label="Server" :value="$department->imap_host" placeholder="imap.example.com" />
                <x-field name="imap_port" label="Port" type="number" :value="$department->imap_port" />
                <x-field name="imap_encryption" label="Encryption" type="select" :options="['ssl' => 'SSL/TLS (993)', 'starttls' => 'STARTTLS (143)', 'none' => 'None']" :value="$department->imap_encryption" />
                <x-field name="imap_folder" label="Folder" :value="$department->imap_folder" />
                <x-field name="imap_username" label="Username" :value="$department->imap_username" autocomplete="off" />
                <x-field name="imap_password" label="Password" type="password" autocomplete="new-password" :placeholder="$editing && $department->imap_password ? 'Saved. Leave blank to keep it' : null" />
            </div>
            <x-field name="imap_after" label="After a message is imported" type="select" :options="['seen' => 'Mark it as read', 'delete' => 'Delete it from the mailbox']" :value="$department->imap_after" />
            <x-field name="imap_validate_cert" type="checkbox" label="Check the server's certificate" :value="$department->imap_validate_cert" help="Turn this off only for self-signed certificates." />
            @if ($editing)
                @if ($department->imap_error)<div class="notice danger" role="alert"><x-icon name="triangle-alert" :size="16" /><div><b>The last check failed.</b> {{ $department->imap_error }}</div></div>@endif
                <div class="notice">
                    <div><b>Test these settings</b><br>Signs in with what is typed above, using the saved password if the field is blank. Nothing is saved until you press Save. @if ($department->imap_checked_at)Last checked {{ $department->imap_checked_at->diffForHumans() }}.@else Not checked yet.@endif</div>
                    <button class="btn alert-action" type="submit" formaction="{{ route('admin.departments.test', $department) }}" formmethod="post" data-no-lock><x-icon name="plug" :size="14" /> Test connection</button>
                </div>
            @else
                <p class="help m-0">Save the department first, then you can test the connection.</p>
            @endif
        </div>
    </div>
    <div class="setting-f"><span class="help">Changes apply to new tickets straight away.</span><div class="row"><a class="btn sm" href="{{ route('admin.departments.index') }}">Cancel</a><button class="btn primary sm" type="submit">{{ $editing ? 'Save changes' : 'Add department' }}</button></div></div>
</form>
@if ($editing)
<form method="POST" action="{{ route('admin.departments.destroy', $department) }}" class="card setting danger-zone form-narrow mt-24" data-confirm="Delete the department {{ $department->name }}? This cannot be undone." data-confirm-label="Delete department" data-danger>@csrf @method('DELETE')
    <div class="setting-h"><h2>Delete this department</h2><p>Removes the department's custom fields and staff links. It is refused while tickets are still in it. Mark it inactive instead to stop new tickets.</p></div>
    <div class="setting-f"><span class="help">This cannot be undone.</span><button class="btn danger sm" type="submit">Delete department</button></div>
</form>
@endif
<script>
(function () {
    var t = document.querySelector('[data-imap-toggle]'), b = document.querySelector('[data-imap-body]');
    if (!t || !b) return;
    function sync() { b.classList.toggle('hidden', !t.checked); }
    t.addEventListener('change', sync); sync();
})();
</script>
@endsection
