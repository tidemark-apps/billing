@extends('layouts.app', ['area' => 'admin'])
@php($editing = $field->exists)
@php($choices = implode("\n", $field->options ?? []))
@section('title', $editing ? 'Edit '.$field->label : 'Add ticket field')
@section('content')
<x-page-head :title="$editing ? $field->label : 'Add ticket field'" :back="route('admin.ticket-fields.index')" backLabel="Ticket fields" />
<form method="POST" action="{{ $editing ? route('admin.ticket-fields.update', $field) : route('admin.ticket-fields.store') }}" class="stack">
    @csrf @if ($editing) @method('PUT') @endif
    <x-card title="Field">
        <div class="grid2">
            <x-field name="label" label="Label" :value="$field->label" required />
            <x-field name="key" label="Key" :value="$field->key" help="Letters, numbers and underscores. Leave blank to make it from the label. Unique within its department." />
            <x-field name="type" label="Type" type="select" :options="\App\Models\TicketField::TYPES" :value="$field->type" />
            <x-field name="department_id" label="Applies to" type="select" :options="['' => 'All departments'] + $departments->pluck('name', 'id')->all()" :value="$field->department_id" />
            <div class="span2"><x-field name="choices" label="Choices" type="textarea" :value="$choices" help="For the Choice type: one per line." /></div>
            <div class="span2"><x-field name="help" label="Help text" :value="$field->help" help="Shown under the input." /></div>
            <x-field name="sort" label="Sort order" type="number" min="0" :value="$field->sort ?? 0" />
        </div>
        <div class="grid3" style="margin-top:16px">
            <x-field name="required" type="checkbox" label="Required" :value="$field->required" />
            <x-field name="staff_only" type="checkbox" label="Staff only" :value="$field->staff_only" help="Filled in and seen by staff only, never shown to the customer." />
            <x-field name="is_active" type="checkbox" label="Active" :value="$field->is_active" />
        </div>
    </x-card>
    <div class="row"><button class="btn primary" type="submit">{{ $editing ? 'Save changes' : 'Add field' }}</button><a class="btn" href="{{ route('admin.ticket-fields.index') }}">Cancel</a></div>
</form>
@endsection
