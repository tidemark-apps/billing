@extends('layouts.app', ['area' => 'admin'])
@php($editing = $field->exists)
@php($choices = implode("\n", $field->options ?? []))
@section('title', $editing ? 'Edit '.$field->label : 'Add ticket field')
@section('content')
<x-page-head :title="$editing ? $field->label : 'Add ticket field'" :back="route('admin.ticket-fields.index')" backLabel="Ticket fields" />
<form method="POST" action="{{ $editing ? route('admin.ticket-fields.update', $field) : route('admin.ticket-fields.store') }}" class="card setting form-narrow" data-validate>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="setting-h"><h2>Question</h2><p>An extra question on the ticket form.</p></div>
    <div class="setting-b">
        <x-field name="label" label="Label" :value="$field->label" aria-required="true" help="What people see above the input, for example Domain name." />
        <div class="grid2">
            <x-field name="type" label="Type" type="select" :options="\App\Models\TicketField::TYPES" :value="$field->type" />
            <x-field name="department_id" label="Applies to" type="select" :options="['' => 'All departments'] + $departments->pluck('name', 'id')->all()" :value="$field->department_id" />
        </div>
        <x-field name="choices" label="Choices (optional)" type="textarea" :value="$choices" help="Only for the Choice type. One option per line." />
        <x-field name="help" label="Help text (optional)" :value="$field->help" help="Shown under the input." />

        <h3>Rules</h3>
        <x-field name="required" type="checkbox" label="Required" :value="$field->required" help="The ticket cannot be submitted without an answer." />
        <x-field name="staff_only" type="checkbox" label="Staff only" :value="$field->staff_only" help="Filled in and seen by staff only. Never shown to the customer." />
        <x-field name="is_active" type="checkbox" label="Active" :value="$field->exists ? $field->is_active : true" help="Inactive fields are hidden from new tickets. Answers already given stay." />

        <h3>Advanced</h3>
        <div class="grid2">
            <x-field name="key" label="Key (optional)" :value="$field->key" help="Letters, numbers and underscores, unique within its department. Made from the label if blank." />
            <x-field name="sort" label="Sort order" type="number" min="0" :value="$field->sort ?? 0" help="Lower numbers come first." />
        </div>
    </div>
    <div class="setting-f"><span class="help">Changes apply to new tickets straight away.</span><div class="row"><a class="btn sm" href="{{ route('admin.ticket-fields.index') }}">Cancel</a><button class="btn primary sm" type="submit">{{ $editing ? 'Save changes' : 'Add field' }}</button></div></div>
</form>
@if ($editing)
<form method="POST" action="{{ route('admin.ticket-fields.destroy', $field) }}" class="card setting danger-zone form-narrow mt-24" data-confirm="Delete the field {{ $field->label }}? Answers already given stay on their tickets." data-confirm-label="Delete field" data-danger>@csrf @method('DELETE')
    <div class="setting-h"><h2>Delete this field</h2><p>Removes the question from the form. Answers already given stay on their tickets. To just stop asking, mark it inactive.</p></div>
    <div class="setting-f"><span class="help">This cannot be undone.</span><button class="btn danger sm" type="submit">Delete field</button></div>
</form>
@endif
@endsection
