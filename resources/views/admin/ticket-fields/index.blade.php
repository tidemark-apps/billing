@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Ticket fields')
@section('content')
<x-page-head title="Ticket fields" sub="Extra questions on the ticket form, for every department or just one."><a class="btn primary" href="{{ route('admin.ticket-fields.create') }}"><x-icon name="plus" :size="15" /> Add field</a></x-page-head>
<x-card :flush="true">
    @if ($fields->isEmpty())<x-empty icon="list-checks" title="No custom fields" text="Ask for an order number, a domain name or anything else you need to answer faster."><a class="btn primary sm" href="{{ route('admin.ticket-fields.create') }}">Add field</a></x-empty>@else
    <table class="t stack-sm"><thead><tr><th>Field</th><th>Type</th><th>Applies to</th><th>Rules</th><th>Status</th></tr></thead><tbody>
    @foreach ($fields as $f)
        <tr class="click" onclick="location.href='{{ route('admin.ticket-fields.edit', $f) }}'">
            <td data-label="Field"><a href="{{ route('admin.ticket-fields.edit', $f) }}"><b>{{ $f->label }}</b></a><div class="muted small mono">{{ $f->key }}</div></td>
            <td data-label="Type">{{ \App\Models\TicketField::TYPES[$f->type] ?? $f->type }}</td>
            <td data-label="Applies to">{{ $f->department?->name ?? 'All departments' }}</td>
            <td data-label="Rules" class="muted">{{ collect([$f->required ? 'Required' : 'Optional', $f->staff_only ? 'Staff only' : 'Shown to customers'])->implode(' · ') }}</td>
            <td data-label="Status"><span class="pill {{ $f->is_active ? 'good' : '' }}">{{ $f->is_active ? 'Active' : 'Inactive' }}</span></td>
        </tr>
    @endforeach</tbody></table>@endif
</x-card>
@endsection
