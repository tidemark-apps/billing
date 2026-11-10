@extends('layouts.app', ['area' => 'admin'])
@php($editing = $staff->exists)
@section('title', $editing ? 'Edit '.$staff->name : 'Add staff')
@section('content')
<x-page-head :title="$editing ? 'Edit staff user' : 'Add staff user'" :back="route('admin.staff.index')" backLabel="Staff" />
<form method="POST" action="{{ $editing ? route('admin.staff.update', $staff) : route('admin.staff.store') }}" class="stack">
    @csrf @if ($editing) @method('PUT') @endif
    <x-card title="Account">
        <div class="grid2">
            <x-field name="name" label="Name" :value="$staff->name" required />
            <x-field name="email" label="Email" type="email" :value="$staff->email" required />
            <x-field name="role" label="Role" type="select" :options="['staff' => 'Staff', 'admin' => 'Administrator']" :value="$staff->role?->value" help="Administrators manage staff, settings, API tokens and plugins. Staff work with clients and billing." />
        </div>
        @if ($lastAdmin)<p class="muted small" style="margin-top:12px">This is the only active administrator, so the role cannot be changed until another administrator exists.</p>@endif
    </x-card>
    <x-card title="Support departments">
        @if ($departments->isEmpty())
            <p class="muted">No departments yet. @if (auth()->user()->isAdmin())<a href="{{ route('admin.departments.create') }}" style="text-decoration:underline">Add one</a> to route support tickets.@endif</p>
        @else
            <div class="stack sm">
                @foreach ($departments as $d)
                    <label class="check"><input type="checkbox" name="departments[]" value="{{ $d->id }}" @checked(in_array($d->id, array_map('intval', (array) old('departments', $selected)), true))> {{ $d->name }}@unless ($d->is_active) <span class="muted">(inactive)</span>@endunless</label>
                @endforeach
            </div>
        @endif
        <p class="help" style="margin-top:12px">Staff in no department only see tickets assigned directly to them; admins see everything.</p>
    </x-card>
    @unless ($editing)
    <x-card title="Password">
        <div class="stack sm">
            <x-field name="password_mode" label="How do they get a password?" type="select" :options="['link' => 'Email them a link to choose one', 'set' => 'I will set one now']" />
            <x-field name="password" label="Password" type="password" autocomplete="new-password" help="Only used when you set the password yourself. At least 8 characters." />
        </div>
    </x-card>
    @endunless
    <div class="row"><button class="btn primary" type="submit">{{ $editing ? 'Save' : 'Add staff user' }}</button><a class="btn ghost" href="{{ route('admin.staff.index') }}">Cancel</a></div>
</form>
@endsection
