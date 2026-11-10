@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Webhook')
@php($last = $deliveries->first())
@section('content')
<x-page-head title="Webhook" :sub="$webhook->url" :back="route('admin.api.index').'#webhooks'" back-label="API & webhooks">
    <form method="POST" action="{{ route('admin.api.webhooks.test', $webhook) }}">@csrf<button class="btn" type="submit">Send test</button></form>
</x-page-head>
<section class="card sumhead" aria-label="Webhook summary">
    <div class="sh-item"><span class="sh-k">Status</span><span class="sh-v"><span class="pill {{ $webhook->is_active ? 'good' : '' }}">{{ $webhook->is_active ? 'Active' : 'Paused' }}</span></span></div>
    <div class="sh-item"><span class="sh-k">Events</span><span class="sh-v">{{ in_array('*', $webhook->events ?? ['*']) ? 'All events' : count($webhook->events).' selected' }}</span></div>
    <div class="sh-item"><span class="sh-k">Last delivery</span><span class="sh-v">@if ($last)@if ($last->success)<span class="pill good">{{ $last->status_code ?: 'OK' }}</span>@else<span class="pill bad">{{ $last->status_code ?: 'Failed' }}</span>@endif @else<span class="muted">None yet</span>@endif</span><span class="sh-c">{{ $last?->created_at->diffForHumans() }}</span></div>
    <div class="sh-item"><span class="sh-k">Recent failures</span><span class="sh-v">{{ $failures }} of {{ $deliveries->count() }}</span></div>
</section>
@if ($errors->any())<div class="alert mb-16" role="alert"><b>{{ $errors->count() }} {{ \Illuminate\Support\Str::plural('field needs', $errors->count()) }} attention. Nothing was saved.</b></div>@endif
<div class="stack">
<form method="POST" action="{{ route('admin.api.webhooks.update', $webhook) }}" class="card setting form-col" data-dirty-form novalidate>
    @csrf @method('PUT')
    <div class="setting-h"><h2>Endpoint</h2><p>Where deliveries go, and which events are sent.</p></div>
    <div class="setting-b">
        <x-system-field name="url" label="Endpoint URL" type="url" :value="$webhook->url" required />
        <x-system-field name="description" label="Description" :optional="true" :value="$webhook->description" />
        <x-system-field name="is_active" label="Send deliveries" type="checkbox" :value="$webhook->is_active" help="Turn off to pause this endpoint without deleting it." />
        <fieldset class="fieldset"><legend>Events <span class="opt">(none ticked = all events)</span></legend>
            <div class="grid2">@foreach ($events as $name => $desc)<label class="check"><input type="checkbox" name="events[]" value="{{ $name }}" @checked(in_array($name, old('events', $webhook->events ?? [])))> <span><span class="mono">{{ $name }}</span> <span class="muted small">· {{ $desc }}</span></span></label>@endforeach</div>
        </fieldset>
    </div>
    <div class="setting-f" data-savebar><span class="help" data-savebar-text>The signing secret was shown once when you added this webhook. To change it, delete the webhook and add it again.</span>
        <div class="row"><button class="btn sm ghost" type="button" data-savebar-discard hidden>Discard</button><button class="btn sm primary" type="submit">Save webhook</button></div></div>
</form>
<x-card title="Recent deliveries" :flush="true">
    <x-slot:actions><span class="help">Latest 50</span></x-slot:actions>
    @if ($deliveries->isEmpty())<x-empty icon="send" title="Nothing delivered yet" text="Use Send test to deliver a ping to this endpoint." />@else
    <table class="t stack-sm"><thead><tr><th>Event</th><th>Result</th><th class="right">Attempts</th><th>When</th><th>Response</th></tr></thead><tbody>
        @foreach ($deliveries as $d)<tr><td data-label="Event" class="mono">{{ $d->event }}</td><td data-label="Result">@if($d->success)<span class="pill good">{{ $d->status_code }}</span>@else<span class="pill bad">{{ $d->status_code ?: 'Failed' }}</span>@endif</td><td data-label="Attempts" class="right">{{ $d->attempts }}</td><td data-label="When" class="muted">{{ $d->created_at->diffForHumans() }}</td><td data-label="Response" class="muted small trunc">{{ $d->response }}</td></tr>@endforeach
    </tbody></table>@endif
</x-card>
<form method="POST" action="{{ route('admin.api.webhooks.destroy', $webhook) }}" class="card setting danger-zone form-col" data-confirm="Delete the webhook {{ $webhook->url }} and its delivery log? This cannot be undone." data-danger data-confirm-label="Delete webhook">
    @csrf @method('DELETE')
    <div class="setting-h"><h2>Delete webhook</h2><p>Stops all deliveries to this URL and removes its delivery log.</p></div>
    <div class="setting-f"><span class="help">This cannot be undone.</span><button class="btn danger sm" type="submit">Delete webhook</button></div>
</form>
</div>
@endsection
