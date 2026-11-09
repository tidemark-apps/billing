@extends('layouts.app', ['area' => 'admin'])
@section('title', 'API & webhooks')
@section('content')
<x-page-head title="API & webhooks" sub="Build your own frontend or connect other systems.">
    <a class="btn" href="{{ $apiUrl }}/openapi.json" target="_blank" rel="noopener"><x-icon name="book-open" :size="15" /> OpenAPI spec</a>
</x-page-head>

@if ($newToken)
    <div class="notice good"><x-icon name="key" :size="16" /><div class="stack sm" style="width:100%"><b>Your new API token</b><div class="secret-box"><span class="grow">{{ $newToken }}</span><button type="button" class="btn sm" data-copy="{{ $newToken }}">Copy</button></div><span class="help">It is shown only once. Send it as <code>Authorization: Bearer …</code>.</span></div></div>
@endif
@if ($newSecret)
    <div class="notice good"><x-icon name="lock" :size="16" /><div class="stack sm" style="width:100%"><b>Webhook signing secret</b><div class="secret-box"><span class="grow">{{ $newSecret }}</span><button type="button" class="btn sm" data-copy="{{ $newSecret }}">Copy</button></div><span class="help">Shown only once. Use it to verify the <code>X-Billing-Signature</code> header.</span></div></div>
@endif

<div class="tabs page-tabs" data-tabs><a href="#tokens" data-tab="tokens">API tokens</a><a href="#webhooks" data-tab="webhooks">Webhooks</a></div>

<div data-panel="tokens" class="stack">
    <x-card title="Tokens" :flush="true">
        @if ($tokens->isEmpty())<x-empty icon="key" title="No tokens yet" text="Create a token to call the admin API from scripts and your own apps." />@else
        <table class="t"><thead><tr><th>Name</th><th>Token</th><th>Access</th><th>Last used</th><th>Expires</th><th></th></tr></thead><tbody>
            @foreach ($tokens as $t)<tr>
                <td>{{ $t->name }}</td><td class="mono">{{ $t->prefix }}…</td>
                <td>@if($t->abilities === null)<span class="pill brand">Full access</span>@else<span class="muted small">{{ count($t->abilities) }} abilities</span>@endif</td>
                <td class="muted">{{ $t->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                <td class="muted">{{ $t->expires_at?->format('j M Y') ?? 'Never' }}</td>
                <td class="right"><form method="POST" action="{{ route('admin.api.tokens.destroy', $t) }}" data-confirm="Revoke this token? Anything using it stops working." data-danger data-confirm-label="Revoke">@csrf @method('DELETE')<button class="btn sm ghost danger" type="submit">Revoke</button></form></td>
            </tr>@endforeach
        </tbody></table>@endif
    </x-card>
    @if (auth()->user()->isAdmin())
    <x-setting title="Create a token" description="Tokens act as you. Use a restricted token for each integration." :action="route('admin.api.tokens.store')">
        <x-field name="name" label="Name" placeholder="e.g. Accounting sync" required />
        <div class="grid2">
            <x-field name="scope" label="Access" type="select" :options="['full' => 'Full access (everything I can do)', 'custom' => 'Only the abilities below']" />
            <x-field name="expires_in" label="Expires after (days)" type="number" placeholder="Never" />
        </div>
        <fieldset style="border:0;padding:0;margin:0"><legend class="help" style="margin-bottom:6px">Abilities (used when access is restricted)</legend>
            <div class="grid3">@foreach (collect(array_keys($abilityCatalog))->groupBy(fn ($a) => explode('.', $a)[0]) as $r => $list)<div class="stack sm"><b style="text-transform:capitalize">{{ str_replace('-', ' ', $r) }}</b>
                @foreach ($list as $a)<label class="check"><input type="checkbox" name="abilities[]" value="{{ $a }}"> {{ ucfirst(explode('.', $a)[1]) }}</label>@endforeach</div>@endforeach</div>
        </fieldset>
        <x-slot:help>Base URL: <code>{{ $apiUrl }}</code></x-slot:help>
        <x-slot:footer><button class="btn primary sm" type="submit">Create token</button></x-slot:footer>
    </x-setting>
    @endif
</div>

<div data-panel="webhooks" class="stack hidden">
    <x-card title="Endpoints" :flush="true">
        @if ($webhooks->isEmpty())<x-empty icon="webhook" title="No webhooks" text="Get a signed POST to your URL whenever an invoice is paid, a service is activated, and more." />@else
        <table class="t"><thead><tr><th>URL</th><th>Events</th><th>Status</th><th class="right">Deliveries</th><th></th></tr></thead><tbody>
            @foreach ($webhooks as $w)<tr>
                <td><a href="{{ route('admin.api.webhooks.show', $w) }}" style="text-decoration:underline;text-underline-offset:3px">{{ \Illuminate\Support\Str::limit($w->url, 60) }}</a>@if($w->description)<div class="muted small">{{ $w->description }}</div>@endif</td>
                <td class="muted small">{{ in_array('*', $w->events ?? ['*']) ? 'All events' : count($w->events).' events' }}</td>
                <td>@if($w->is_active)<span class="pill good">Active</span>@else<span class="pill">Paused</span>@endif</td>
                <td class="right">{{ $w->deliveries_count }}</td>
                <td class="right"><form method="POST" action="{{ route('admin.api.webhooks.test', $w) }}">@csrf<button class="btn sm" type="submit">Send test</button></form></td>
            </tr>@endforeach
        </tbody></table>@endif
    </x-card>
    @if (auth()->user()->isAdmin())
    <x-setting title="Add a webhook" description="We POST JSON to your URL. Each request is signed with HMAC-SHA256 so you can verify it came from here." :action="route('admin.api.webhooks.store')">
        <x-field name="url" label="Endpoint URL" type="url" placeholder="https://example.com/hooks/billing" required />
        <x-field name="description" label="Description" />
        <fieldset style="border:0;padding:0;margin:0"><legend class="help" style="margin-bottom:6px">Events (none selected = all events)</legend>
            <div class="grid2">@foreach ($events as $name => $desc)<label class="check"><input type="checkbox" name="events[]" value="{{ $name }}"> <span><span class="mono">{{ $name }}</span> <span class="muted small">· {{ $desc }}</span></span></label>@endforeach</div>
        </fieldset>
        <x-slot:footer><button class="btn primary sm" type="submit">Add webhook</button></x-slot:footer>
    </x-setting>
    @endif
</div>
@endsection
