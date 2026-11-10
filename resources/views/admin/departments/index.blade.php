@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Departments')
@section('content')
<x-page-head title="Departments" sub="Queues that support tickets are sorted into, each optionally fed by one email address."><a class="btn primary" href="{{ route('admin.departments.create') }}"><x-icon name="plus" :size="15" /> Add department</a></x-page-head>
<x-card :flush="true">
    @if ($departments->isEmpty())<x-empty icon="layers" title="No departments yet" text="Customers choose a department when they open a ticket. Add at least one."><a class="btn primary sm" href="{{ route('admin.departments.create') }}">Add department</a></x-empty>@else
    <table class="t stack-sm"><thead><tr><th>Department</th><th>Email intake</th><th>Customers can pick it</th><th class="right">Staff</th><th class="right">Tickets</th><th>Status</th></tr></thead><tbody>
    @foreach ($departments as $d)
        <tr class="click" onclick="location.href='{{ route('admin.departments.edit', $d) }}'">
            <td data-label="Department"><a href="{{ route('admin.departments.edit', $d) }}"><b>{{ $d->name }}</b></a>@if ($d->description)<div class="muted small">{{ \Illuminate\Support\Str::limit($d->description, 80) }}</div>@endif</td>
            <td data-label="Email intake">@if ($d->email)<span class="mono">{{ $d->email }}</span>@if ($d->imap_enabled)<div class="small {{ $d->imap_error ? 'bad-text' : 'muted' }}">{{ $d->imap_error ? 'IMAP error: check the connection' : 'Pulled over IMAP' }}</div>@endif @else<span class="muted">—</span>@endif</td>
            <td data-label="Customers can pick it">{{ $d->is_public ? 'Yes, listed' : 'No, unlisted' }}</td>
            <td data-label="Staff" class="right">{{ $d->staff_count }}</td>
            <td data-label="Tickets" class="right">{{ $d->tickets_count }}</td>
            <td data-label="Status"><span class="pill {{ $d->is_active ? 'good' : '' }}">{{ $d->is_active ? 'Active' : 'Inactive' }}</span></td>
        </tr>
    @endforeach</tbody></table>@endif
</x-card>
@endsection
