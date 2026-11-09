@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Webhook')
@section('content')
<x-page-head title="Webhook" :sub="$webhook->url" :back="route('admin.api.index').'#webhooks'" back-label="API & webhooks">
    <form method="POST" action="{{ route('admin.api.webhooks.test', $webhook) }}">@csrf<button class="btn" type="submit">Send test</button></form>
</x-page-head>
<x-setting title="Settings" :action="route('admin.api.webhooks.update', $webhook)" method="PUT">
    <x-field name="url" label="Endpoint URL" type="url" :value="$webhook->url" required />
    <x-field name="description" label="Description" :value="$webhook->description" />
    <x-field name="is_active" label="Active" type="checkbox" :value="$webhook->is_active" />
    <fieldset style="border:0;padding:0;margin:0"><legend class="help" style="margin-bottom:6px">Events (none selected = all events)</legend>
        <div class="grid2">@foreach ($events as $name => $desc)<label class="check"><input type="checkbox" name="events[]" value="{{ $name }}" @checked(in_array($name, $webhook->events ?? []))> <span class="mono">{{ $name }}</span></label>@endforeach</div>
    </fieldset>
</x-setting>
<x-card title="Recent deliveries" :flush="true">
    @if ($deliveries->isEmpty())<x-empty icon="send" title="Nothing delivered yet" />@else
    <table class="t"><thead><tr><th>Event</th><th>Result</th><th>Attempts</th><th>When</th><th>Response</th></tr></thead><tbody>
        @foreach ($deliveries as $d)<tr><td class="mono">{{ $d->event }}</td><td>@if($d->success)<span class="pill good">{{ $d->status_code }}</span>@else<span class="pill bad">{{ $d->status_code ?: 'Failed' }}</span>@endif</td><td>{{ $d->attempts }}</td><td class="muted">{{ $d->created_at->diffForHumans() }}</td><td class="muted small trunc" style="max-width:280px">{{ $d->response }}</td></tr>@endforeach
    </tbody></table>@endif
</x-card>
<form method="POST" action="{{ route('admin.api.webhooks.destroy', $webhook) }}" data-confirm="Delete this webhook and its delivery log?" data-danger data-confirm-label="Delete" style="margin-top:16px">@csrf @method('DELETE')<button class="btn danger" type="submit">Delete webhook</button></form>
@endsection
