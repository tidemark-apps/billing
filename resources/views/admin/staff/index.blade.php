@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Staff')
@push('head') @include('admin.partials.filters-style') @endpush
@section('content')
<x-page-head title="Staff" sub="People who can sign in to the admin area. Administrators manage everything; staff work with clients and billing."><a class="btn primary" href="{{ route('admin.staff.create') }}"><x-icon name="plus" :size="15" /> Add staff</a></x-page-head>
<x-view-tabs :tabs="['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'admins' => 'Administrators', 'no2fa' => 'No 2FA']" :current="$view" :counts="$counts" label="Staff views" />
<form class="filters" method="GET" action="{{ route('admin.staff.index') }}">
    @if ($view !== 'all')<input type="hidden" name="view" value="{{ $view }}">@endif
    <div class="searchbox"><x-icon name="search" :size="15" /><input type="search" name="q" value="{{ $q }}" placeholder="Search name or email…" aria-label="Search staff"></div>
    <button class="btn" type="submit">Search</button>
    @if ($q !== '')<a class="btn ghost" href="{{ route('admin.staff.index', $view === 'all' ? [] : ['view' => $view]) }}">Clear search</a>@endif
</form>
<x-card :flush="true">
    @if ($users->isEmpty())
        <x-empty icon="users" :title="$q !== '' ? 'No one matches that search' : 'Nobody in this view'" :text="$q !== '' ? 'Try a different name or email, or clear the search.' : ($view === 'no2fa' ? 'Every active staff member has two-factor authentication on.' : 'Staff accounts that match this view will show up here.')">
            <a class="btn" href="{{ route('admin.staff.index') }}">Show everyone</a>
        </x-empty>
    @else
    <table class="t stack-sm"><thead><tr><th>Name</th><th>Role</th><th>Departments</th><th>Last sign-in</th><th>2FA</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody>
        @foreach ($users as $u)
        @php($me = $u->id === auth()->id())
        <tr>
            <td data-label="Name"><div class="row"><span class="avatar sm" aria-hidden="true">{{ $u->initials() }}</span><div><a href="{{ route('admin.staff.edit', $u) }}"><b>{{ $u->name }}</b></a>@if ($me) <span class="pill">You</span>@endif<div class="muted small">{{ $u->email }}</div></div></div></td>
            <td data-label="Role"><span class="pill {{ $u->isAdmin() ? 'brand' : '' }}">{{ $u->isAdmin() ? 'Administrator' : 'Staff' }}</span></td>
            <td data-label="Departments">@if ($u->isAdmin())<span class="muted">All</span>@else{{ $u->departments->pluck('name')->implode(', ') ?: '—' }}@endif</td>
            <td data-label="Last sign-in">{{ $u->last_login_at?->diffForHumans() ?? 'Never' }}</td>
            <td data-label="2FA">@if ($u->hasTwoFactor())<span class="pill good">On</span>@else<span class="pill {{ $twoFactorRequired && $u->is_active ? 'warn' : '' }}">Off</span>@endif</td>
            <td data-label="Status"><span class="pill {{ $u->is_active ? 'good' : '' }}">{{ $u->is_active ? 'Active' : 'Inactive' }}</span></td>
            <td class="right"><div class="row end">
                <a class="btn" href="{{ route('admin.staff.edit', $u) }}">Edit</a>
                <x-menu label="More">
                    @if ($u->is_active)
                        <form method="POST" action="{{ route('admin.staff.password-reset-link', $u) }}">@csrf<button class="item" type="submit" role="menuitem">Send password reset link</button></form>
                    @endif
                    @if ($u->hasTwoFactor() && ! $me)
                        <a class="item" role="menuitem" href="{{ route('admin.staff.edit', $u) }}#security">Reset 2FA…</a>
                    @endif
                    @if ($u->is_active && ! $me && $u->id !== $lastAdminId)
                        <hr>
                        <form method="POST" action="{{ route('admin.staff.deactivate', $u) }}" data-confirm="Deactivate {{ $u->email }}? They are signed out where possible and their API tokens are revoked. You can activate them again later." data-confirm-label="Deactivate" data-danger>@csrf<button class="item danger" type="submit" role="menuitem">Deactivate</button></form>
                    @elseif (! $u->is_active)
                        <form method="POST" action="{{ route('admin.staff.activate', $u) }}">@csrf<button class="item" type="submit" role="menuitem">Activate</button></form>
                    @endif
                </x-menu>
            </div></td>
        </tr>
        @endforeach
    </tbody></table>
    @endif
</x-card>
@endsection
