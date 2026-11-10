@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Staff')
@section('content')
<x-page-head title="Staff" sub="People who can sign in to the admin area."><a class="btn primary" href="{{ route('admin.staff.create') }}"><x-icon name="plus" :size="15" /> Add staff</a></x-page-head>
<x-card :flush="true">
    <table class="t stack-sm"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Departments</th><th>Last sign-in</th><th>2FA</th><th>Status</th><th></th></tr></thead><tbody>
        @foreach ($users as $u)
        @php($me = $u->id === auth()->id())
        <tr>
            <td data-label="Name"><b>{{ $u->name }}</b>@if ($me) <span class="pill">You</span>@endif</td>
            <td data-label="Email">{{ $u->email }}</td>
            <td data-label="Role"><span class="pill {{ $u->isAdmin() ? 'brand' : '' }}">{{ $u->isAdmin() ? 'Administrator' : 'Staff' }}</span></td>
            <td data-label="Departments">@if ($u->isAdmin())<span class="muted">All</span>@else{{ $u->departments->pluck('name')->implode(', ') ?: '—' }}@endif</td>
            <td data-label="Last sign-in">{{ $u->last_login_at?->diffForHumans() ?? 'Never' }}</td>
            <td data-label="2FA">
                @if ($u->hasTwoFactor())
                    @if ($me)<span class="pill good">On</span>
                    @else
                    <form method="POST" action="{{ route('admin.users.two-factor.reset', $u) }}" class="row" data-confirm="Reset two-factor authentication for {{ $u->email }}? They can sign in with just their password until they set it up again. Tick the box to also revoke their API tokens (do this if the account may be compromised)." data-confirm-label="Reset" data-danger>@csrf
                        <span class="pill good">On</span>
                        <label class="check" title="Also revoke this person's API tokens"><input type="checkbox" name="revoke_tokens" value="1"> revoke API tokens</label>
                        <button class="btn sm" type="submit">Reset 2FA</button>
                    </form>
                    @endif
                @else
                    <span class="muted">Off</span>
                @endif
            </td>
            <td data-label="Status"><span class="pill {{ $u->is_active ? 'good' : '' }}">{{ $u->is_active ? 'Active' : 'Inactive' }}</span></td>
            <td class="right"><div class="row" style="justify-content:flex-end">
                <a class="btn sm ghost" href="{{ route('admin.staff.edit', $u) }}">Edit</a>
                @if ($u->is_active)
                    <form method="POST" action="{{ route('admin.staff.password-reset-link', $u) }}">@csrf<button class="btn sm ghost" type="submit">Send reset link</button></form>
                @endif
                @if ($u->is_active && ! $me && $u->id !== $lastAdminId)
                    <form method="POST" action="{{ route('admin.staff.deactivate', $u) }}" data-confirm="Deactivate {{ $u->email }}? They are signed out where possible and their API tokens are revoked." data-confirm-label="Deactivate" data-danger>@csrf<button class="btn sm ghost danger" type="submit">Deactivate</button></form>
                @elseif (! $u->is_active)
                    <form method="POST" action="{{ route('admin.staff.activate', $u) }}">@csrf<button class="btn sm" type="submit">Activate</button></form>
                @endif
            </div></td>
        </tr>
        @endforeach
    </tbody></table>
</x-card>
@endsection
