@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Ticket fields')
@section('content')
<x-page-head title="Ticket fields" sub="Extra questions on the ticket form, for every department or just one."><a class="btn primary" href="{{ route('admin.ticket-fields.create') }}"><x-icon name="plus" :size="15" /> Add field</a></x-page-head>
<x-card :flush="true">
    @if ($fields->isEmpty())<x-empty icon="list-checks" title="No custom fields" text="Ask for an order number, a domain name or anything else you need to answer faster."><a class="btn primary sm" href="{{ route('admin.ticket-fields.create') }}">Add field</a></x-empty>@else
    <table class="t stack-sm"><thead><tr><th>Field</th><th>Key</th><th>Type</th><th>Applies to</th><th>Options</th><th></th></tr></thead><tbody>
    @foreach ($fields as $f)
        <tr>
            <td data-label="Field"><a href="{{ route('admin.ticket-fields.edit', $f) }}"><b>{{ $f->label }}</b></a></td>
            <td data-label="Key" class="mono">{{ $f->key }}</td>
            <td data-label="Type">{{ \App\Models\TicketField::TYPES[$f->type] ?? $f->type }}</td>
            <td data-label="Applies to">{{ $f->department?->name ?? 'All departments' }}</td>
            <td data-label="Options">@if ($f->required)<span class="pill warn">Required</span> @endif @if ($f->staff_only)<span class="pill brand">Staff only</span> @endif @unless ($f->is_active)<span class="pill">Inactive</span>@endunless</td>
            <td class="right"><div class="row" style="justify-content:flex-end"><a class="btn sm ghost" href="{{ route('admin.ticket-fields.edit', $f) }}">Edit</a>
                <form method="POST" action="{{ route('admin.ticket-fields.destroy', $f) }}" data-confirm="Delete the field “{{ $f->label }}”? Answers already given stay on their tickets." data-confirm-label="Delete" data-danger>@csrf @method('DELETE')<button class="btn sm ghost danger" type="submit">Delete</button></form></div></td>
        </tr>
    @endforeach</tbody></table>@endif
</x-card>
@endsection
