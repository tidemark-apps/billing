@extends('layouts.app', ['area' => 'admin'])
@section('title', 'New ticket')
@section('content')
<x-page-head title="New ticket" sub="Open a ticket on behalf of a client. They are emailed a copy." :back="route('admin.tickets.index')" backLabel="Tickets" />
@if ($departments->isEmpty())
    <div class="notice warn"><x-icon name="triangle-alert" :size="16" /><div>There is no active department you work in, so you cannot open tickets yet.</div></div>
@else
<form method="POST" action="{{ route('admin.tickets.store') }}" enctype="multipart/form-data" class="card setting form-narrow" data-validate>
    @csrf
    <div class="setting-h"><h2>Ticket details</h2><p>Choose who it is for, then write the first message.</p></div>
    <div class="setting-b">
        <x-field name="client_id" label="Client" type="select" aria-required="true" :options="['' => 'Choose a client…'] + $clients->mapWithKeys(fn ($c) => [$c->id => $c->displayName().' ('.$c->email.')'])->all()" :value="$clientId ?: null" />
        <x-field name="service_id" label="Related service (optional)" type="select" help="Must belong to the client you chose above." :options="['' => 'None'] + $services->mapWithKeys(fn ($s) => [$s->id => ($s->client?->displayName() ?? '?').' — '.$s->title()])->all()" />
        <div class="grid2">
            <x-field name="department_id" label="Department" type="select" aria-required="true" data-reveal="dept" help="Decides who sees it and which extra questions appear." :options="$departments->pluck('name', 'id')->all()" />
            <x-field name="priority" label="Priority" type="select" help="Normal unless it is blocking the customer." :options="collect(\App\Enums\TicketPriority::cases())->mapWithKeys(fn ($p) => [$p->value => $p->label()])->all()" value="normal" />
        </div>
        <hr class="m-0">
        <x-field name="subject" label="Subject" maxlength="200" aria-required="true" />
        <x-field name="body" label="Message" type="textarea" rows="8" aria-required="true" help="The client is emailed this message and can reply from their portal or by email." />
        <label for="f-attachments"><span>Attachments (optional)</span><input id="f-attachments" type="file" name="attachments[]" multiple>@error('attachments')<span class="field-error">{{ $message }}</span>@enderror</label>
        @foreach ($departments as $d)
            @php($fs = $fieldsByDepartment[$d->id])
            @if ($fs->isNotEmpty())
            <div class="stack" data-reveal-group="dept" data-reveal-for="{{ $d->id }}"><h3>{{ $d->name }} details</h3>@include('admin.tickets._fields', ['fields' => $fs, 'values' => old('custom', [])])</div>
            @endif
        @endforeach
    </div>
    <div class="setting-f"><span class="help">The client gets an email with this message.</span><div class="row"><a class="btn sm" href="{{ route('admin.tickets.index') }}">Cancel</a><button class="btn primary sm" type="submit">Open ticket</button></div></div>
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
