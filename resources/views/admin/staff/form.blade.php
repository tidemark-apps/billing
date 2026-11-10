@extends('layouts.app', ['area' => 'admin'])
@php($editing = $staff->exists)
@php($me = $editing && $staff->id === auth()->id())
@php($roleNow = old('role', $staff->role?->value ?? 'staff'))
@section('title', $editing ? 'Edit '.$staff->name : 'Add staff')
@section('content')
<x-page-head :title="$editing ? $staff->name : 'Add staff user'" :sub="$editing ? $staff->email : 'They can sign in to the admin area once added.'" :back="route('admin.staff.index')" backLabel="Staff" />

@if ($editing)
<section class="card sumhead" aria-label="Staff summary">
    <div class="sh-item"><span class="sh-k">Role</span><span class="sh-v"><span class="pill {{ $staff->isAdmin() ? 'brand' : '' }}">{{ $staff->isAdmin() ? 'Administrator' : 'Staff' }}</span></span></div>
    <div class="sh-item"><span class="sh-k">Status</span><span class="sh-v"><span class="pill {{ $staff->is_active ? 'good' : '' }}">{{ $staff->is_active ? 'Active' : 'Inactive' }}</span></span></div>
    <div class="sh-item"><span class="sh-k">Last sign-in</span><span class="sh-v">{{ $staff->last_login_at?->diffForHumans() ?? 'Never' }}</span></div>
    <div class="sh-item"><span class="sh-k">Two-factor</span><span class="sh-v"><span class="pill {{ $staff->hasTwoFactor() ? 'good' : '' }}">{{ $staff->hasTwoFactor() ? 'On' : 'Off' }}</span></span></div>
</section>
@endif

<div class="form-col">
@if ($errors->any())
    <div class="alert" role="alert"><b>{{ $errors->count() }} {{ \Illuminate\Support\Str::plural('field needs', $errors->count()) }} attention. Nothing was saved.</b></div>
@endif

<form method="POST" action="{{ $editing ? route('admin.staff.update', $staff) : route('admin.staff.store') }}" class="card setting" data-dirty-form novalidate>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="setting-h"><h2>{{ $editing ? 'Account' : 'New staff user' }}</h2><p>Their name, email, role and the support departments they work in.</p></div>
    <div class="setting-b">
        <x-system-field name="name" label="Name" :value="$staff->name" required autocomplete="name" />
        <x-system-field name="email" label="Email" type="email" :value="$staff->email" required autocomplete="off" help="They sign in with this address." />

        <h3>Role</h3>
        <fieldset class="fieldset" aria-label="Role">
            <label class="row"><input type="radio" name="role" value="staff" @checked($roleNow === 'staff')><div><b>Staff</b><div class="help">Works with clients, invoices, quotes, services and support tickets. Cannot change settings, plugins, API tokens or other staff.</div></div></label>
            <label class="row"><input type="radio" name="role" value="admin" @checked($roleNow === 'admin')><div><b>Administrator</b><div class="help">Everything staff can do, plus settings, staff, plugins, API tokens and webhooks. Sees every ticket.</div></div></label>
            @error('role')<span class="field-error">{{ $message }}</span>@enderror
        </fieldset>
        @if ($editing && $lastAdmin)<div class="notice warn"><x-icon name="triangle-alert" :size="16" /><div>This is the only active administrator, so the role cannot be changed until another administrator exists.</div></div>@endif

        <h3>Support departments</h3>
        <p class="help m-0">Staff see tickets in their departments and any assigned to them. Administrators see every ticket.</p>
        @if ($departments->isEmpty())
            <p class="muted m-0">No departments yet. @if (auth()->user()->isAdmin())<a class="link-underline" href="{{ route('admin.departments.create') }}">Add one</a> to route support tickets.@endif</p>
        @else
            @foreach ($departments as $d)
                <label class="check"><input type="checkbox" name="departments[]" value="{{ $d->id }}" @checked(in_array($d->id, array_map('intval', (array) old('departments', $selected)), true))> {{ $d->name }}@unless ($d->is_active) <span class="muted">(inactive)</span>@endunless</label>
            @endforeach
        @endif

        @unless ($editing)
        <h3>Password</h3>
        <x-system-field name="password_mode" label="How do they get a password?" type="select" :options="['link' => 'Email them a link to choose one', 'set' => 'I will set one now']" />
        <x-system-field name="password" label="Password" type="password" :optional="true" autocomplete="new-password" help="Only used when you set the password yourself. At least 8 characters." />
        @endunless
    </div>
    <div class="setting-f" data-savebar><span class="help" data-savebar-text>{{ $editing ? 'Role and department changes apply the next time they load a page.' : 'They can sign in as soon as you add them.' }}</span>
        <div class="row"><a class="btn sm ghost" href="{{ route('admin.staff.index') }}">Cancel</a><button class="btn sm ghost" type="button" data-savebar-discard hidden>Discard</button><button class="btn sm primary" type="submit">{{ $editing ? 'Save changes' : 'Add staff user' }}</button></div></div>
</form>

@if ($editing)
    <section class="card setting" id="security">
        <div class="setting-h"><h2>Sign-in security</h2><p>Help them back in, or reset their second factor if they lost their phone.</p></div>
        <div class="setting-b">
            @if ($staff->is_active)
            <div class="row spread"><div><b>Password</b><div class="help">Emails them a link to choose a new password.</div></div>
                <form method="POST" action="{{ route('admin.staff.password-reset-link', $staff) }}">@csrf<button class="btn sm" type="submit">Send reset link</button></form></div>
            @endif
            <div class="row spread"><div><b>Two-factor authentication</b> <span class="pill {{ $staff->hasTwoFactor() ? 'good' : '' }}">{{ $staff->hasTwoFactor() ? 'On' : 'Off' }}</span>
                <div class="help">@if ($me)Manage your own from <a class="link-underline" href="{{ route('admin.account.security.index') }}">your security page</a>.@elseif ($staff->hasTwoFactor())They can sign in with only a password until they set it up again.@else They have not set it up.@endif</div></div>
                @if ($staff->hasTwoFactor() && ! $me)
                <form method="POST" action="{{ route('admin.users.two-factor.reset', $staff) }}" class="stack sm" data-confirm="Reset two-factor authentication for {{ $staff->email }}? They can sign in with just their password until they set it up again." data-confirm-label="Reset 2FA" data-danger>@csrf
                    <label class="check"><input type="checkbox" name="revoke_tokens" value="1"> Also revoke their API tokens (if the account may be compromised)</label>
                    <button class="btn sm danger" type="submit">Reset 2FA</button>
                </form>
                @endif
            </div>
        </div>
    </section>
    @unless ($me)
    <section class="card setting danger-zone">
        <div class="setting-h"><h2>{{ $staff->is_active ? 'Deactivate account' : 'Activate account' }}</h2><p>{{ $staff->is_active ? 'They are signed out where possible and their API tokens are revoked. Their history is kept and you can activate them again.' : 'Inactive accounts cannot sign in. Activating lets them sign in again with their existing password.' }}</p></div>
        <div class="setting-f">
            @if ($staff->is_active && $lastAdmin)<span class="help">The only active administrator cannot be deactivated.</span>
            @elseif ($staff->is_active)<span class="help">Status: Active</span>
                <form method="POST" action="{{ route('admin.staff.deactivate', $staff) }}" data-confirm="Deactivate {{ $staff->email }}? They are signed out where possible and their API tokens are revoked." data-confirm-label="Deactivate" data-danger>@csrf<button class="btn danger sm" type="submit">Deactivate</button></form>
            @else<span class="help">Status: Inactive</span>
                <form method="POST" action="{{ route('admin.staff.activate', $staff) }}">@csrf<button class="btn primary sm" type="submit">Activate</button></form>
            @endif
        </div>
    </section>
    @endunless
@endif
</div>
@endsection
