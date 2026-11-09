@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Licence')
@section('content')
<x-page-head :title="$license->key" :sub="$license->product_code" :back="route('admin.license-server.index')" back-label="Licenses">
    @if ($license->status === 'revoked' || $license->status === 'suspended')
        <form method="POST" action="{{ route('admin.license-server.reactivate', $license) }}">@csrf<button class="btn" type="submit">Reactivate</button></form>
    @endif
    @if ($license->status !== 'revoked')
        <form method="POST" action="{{ route('admin.license-server.revoke', $license) }}" data-confirm="Revoke this licence? Software using it will stop validating." data-danger data-confirm-label="Revoke">@csrf<button class="btn danger" type="submit">Revoke</button></form>
    @endif
</x-page-head>
<div class="grid2">
    <x-card title="Details">
        <dl class="kv wide">
            <dt>Status</dt><dd><span class="pill {{ ['active' => 'good', 'grace' => 'warn', 'suspended' => 'warn', 'revoked' => 'bad', 'expired' => 'bad'][$license->effectiveStatus()] ?? '' }}">{{ ucfirst($license->effectiveStatus()) }}</span></dd>
            <dt>Client</dt><dd>{{ $license->service?->client?->displayName() ?? '—' }}</dd>
            <dt>Service</dt><dd>@if ($license->service)@if (Route::has('admin.services.show'))<a href="{{ route('admin.services.show', $license->service) }}" style="text-decoration:underline">{{ $license->service->title() }}</a>@else{{ $license->service->title() }}@endif @else — @endif</dd>
            <dt>Binding</dt><dd>{{ ucfirst($license->binding) }}</dd>
            <dt>Grace days</dt><dd>{{ $license->grace_days }}</dd>
            <dt>Expires</dt><dd>{{ $license->expires_at?->format('j M Y') ?? 'Follows billing status' }}</dd>
            <dt>Issued</dt><dd>{{ $license->created_at->format('j M Y') }}</dd>
        </dl>
    </x-card>
    <x-setting title="Limits & expiry" :action="route('admin.license-server.update', $license)" method="PATCH">
        <x-field name="max_activations" label="Max activations" type="number" :value="$license->max_activations" min="1" />
        <x-field name="expires_on" label="Expires on" type="date" :value="$license->expires_at?->format('Y-m-d')" help="Leave empty to follow the billing status." />
        <x-field name="extend_days" label="…or extend by (days)" type="number" min="1" />
        <x-field name="clear_expiry" label="Remove expiry date" type="checkbox" />
    </x-setting>
</div>
<x-card title="Activations ({{ $license->activations->count() }} / {{ $license->max_activations }})" :flush="true">
    @if ($license->activations->isEmpty())<x-empty icon="monitor" title="Not activated yet" />@else
    <div class="tscroll"><table class="t"><thead><tr><th>Identifier</th><th>IP</th><th>Version</th><th>First seen</th><th>Last seen</th><th></th></tr></thead><tbody>
        @foreach ($license->activations as $a)<tr>
            <td class="mono">{{ $a->identifier }}</td><td>{{ $a->ip }}</td><td>{{ $a->version ?? '—' }}</td><td>{{ $a->first_seen_at?->format('j M Y H:i') }}</td><td>{{ $a->last_seen_at?->diffForHumans() }}</td>
            <td class="right"><form method="POST" action="{{ route('admin.license-server.activations.destroy', [$license, $a]) }}" data-confirm="Remove this activation?">@csrf @method('DELETE')<button class="btn sm ghost danger" type="submit">Remove</button></form></td>
        </tr>@endforeach
    </tbody></table></div>@endif
</x-card>
@endsection
