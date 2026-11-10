@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Departments')
@section('content')
<x-page-head title="Departments" sub="Queues that support tickets are sorted into, each optionally fed by one email address."><a class="btn primary" href="{{ route('admin.departments.create') }}"><x-icon name="plus" :size="15" /> Add department</a></x-page-head>
<x-card :flush="true">
    @if ($departments->isEmpty())<x-empty icon="layers" title="No departments yet" text="Customers choose a department when they open a ticket. Add at least one."><a class="btn primary sm" href="{{ route('admin.departments.create') }}">Add department</a></x-empty>@else
    <table class="t stack-sm"><thead><tr><th>Department</th><th>Email</th><th>Visibility</th><th class="right">Staff</th><th class="right">Tickets</th><th>Status</th><th></th></tr></thead><tbody>
    @foreach ($departments as $d)
        <tr>
            <td data-label="Department"><a href="{{ route('admin.departments.edit', $d) }}"><b>{{ $d->name }}</b></a></td>
            <td data-label="Email" class="mono">{{ $d->email ?: '—' }}@if ($d->imap_enabled) <span class="pill {{ $d->imap_error ? 'bad' : 'outline' }}">IMAP</span>@endif</td>
            <td data-label="Visibility"><span class="pill {{ $d->is_public ? '' : 'outline' }}">{{ $d->is_public ? 'Listed' : 'Unlisted' }}</span></td>
            <td data-label="Staff" class="right">{{ $d->staff_count }}</td>
            <td data-label="Tickets" class="right">{{ $d->tickets_count }}</td>
            <td data-label="Status"><span class="pill {{ $d->is_active ? 'good' : '' }}">{{ $d->is_active ? 'Active' : 'Inactive' }}</span></td>
            <td class="right"><div class="row" style="justify-content:flex-end"><a class="btn sm ghost" href="{{ route('admin.departments.edit', $d) }}">Edit</a>
                <form method="POST" action="{{ route('admin.departments.destroy', $d) }}" data-confirm="Delete {{ $d->name }}?" data-confirm-label="Delete" data-danger>@csrf @method('DELETE')<button class="btn sm ghost danger" type="submit">Delete</button></form></div></td>
        </tr>
    @endforeach</tbody></table>@endif
</x-card>
@endsection
