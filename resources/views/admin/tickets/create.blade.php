@extends('layouts.app', ['area' => 'admin'])
@section('title', 'New ticket')
@section('content')
<x-page-head title="New ticket" sub="Open a ticket on behalf of a client. They are emailed a copy." :back="route('admin.tickets.index')" backLabel="Tickets" />
@if ($departments->isEmpty())
    <div class="notice warn"><x-icon name="triangle-alert" :size="16" /><div>There is no active department you work in, so you cannot open tickets yet.</div></div>
@else
<form method="POST" action="{{ route('admin.tickets.store') }}" enctype="multipart/form-data" class="stack">
    @csrf
    <x-card title="Who and where">
        <div class="grid2">
            <x-field name="client_id" label="Client" type="select" required :options="['' => 'Choose a client…'] + $clients->mapWithKeys(fn ($c) => [$c->id => $c->displayName().' ('.$c->email.')'])->all()" :value="$clientId ?: null" />
            <x-field name="service_id" label="Related service (optional)" type="select" help="Must belong to the chosen client." :options="['' => 'None'] + $services->mapWithKeys(fn ($s) => [$s->id => ($s->client?->displayName() ?? '?').' — '.$s->title()])->all()" />
            <x-field name="department_id" label="Department" type="select" required data-reveal="dept" :options="$departments->pluck('name', 'id')->all()" />
            <x-field name="priority" label="Priority" type="select" :options="collect(\App\Enums\TicketPriority::cases())->mapWithKeys(fn ($p) => [$p->value => $p->label()])->all()" value="normal" />
        </div>
    </x-card>
    <x-card title="Message">
        <div class="stack">
            <x-field name="subject" label="Subject" required />
            <x-field name="body" label="Message" type="textarea" required rows="8" />
            <label for="f-attachments"><span>Attachments</span><input id="f-attachments" type="file" name="attachments[]" multiple>@error('attachments')<span class="field-error">{{ $message }}</span>@enderror</label>
        </div>
    </x-card>
    @foreach ($departments as $d)
        @php($fs = $fieldsByDepartment[$d->id])
        @if ($fs->isNotEmpty())
        <div data-reveal-group="dept" data-reveal-for="{{ $d->id }}"><x-card title="{{ $d->name }} details"><div class="stack">@include('admin.tickets._fields', ['fields' => $fs, 'values' => old('custom', [])])</div></x-card></div>
        @endif
    @endforeach
    <div class="row"><button class="btn primary" type="submit">Open ticket</button><a class="btn" href="{{ route('admin.tickets.index') }}">Cancel</a></div>
</form>
<script>
// Only the chosen department's custom fields are submitted (hidden groups are disabled).
(function () {
    var sel = document.getElementById('f-department_id'); if (!sel) return;
    function sync() { document.querySelectorAll('[data-reveal-group="dept"]').forEach(function (g) {
        var off = g.dataset.revealFor !== sel.value; g.classList.toggle('hidden', off);
        g.querySelectorAll('input,select,textarea').forEach(function (i) { i.disabled = off; }); }); }
    sel.addEventListener('change', sync); sync();
})();
</script>
@endif
@endsection
