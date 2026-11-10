@extends('layouts.app', ['area' => 'admin'])
@section('title', 'API & webhooks')
@section('content')
<x-page-head title="API & webhooks" sub="Build your own frontend or connect other systems.">
    <a class="btn" href="{{ $apiUrl }}/openapi.json" target="_blank" rel="noopener"><x-icon name="book-open" :size="15" /> OpenAPI spec</a>
</x-page-head>

@if ($newToken)
    <div class="notice good" role="status"><x-icon name="key" :size="16" /><div class="stack sm w-full"><b>Copy your new API token now</b><div class="secret-box"><span class="grow">{{ $newToken }}</span><button type="button" class="btn sm primary" data-copy="{{ $newToken }}"><x-icon name="copy" :size="14" /> Copy</button></div><span class="help">It is shown only once and cannot be recovered later. Send it as <code>Authorization: Bearer …</code>.</span></div></div>
@endif
@if ($newSecret)
    <div class="notice good" role="status"><x-icon name="lock" :size="16" /><div class="stack sm w-full"><b>Copy the webhook signing secret now</b><div class="secret-box"><span class="grow">{{ $newSecret }}</span><button type="button" class="btn sm primary" data-copy="{{ $newSecret }}"><x-icon name="copy" :size="14" /> Copy</button></div><span class="help">Shown only once. Use it to verify the <code>X-Billing-Signature</code> header (HMAC-SHA256 of the request body).</span></div></div>
@endif

<div class="tabs page-tabs mb-16" data-tabs role="tablist" aria-label="API sections"><a href="#tokens" data-tab="tokens" role="tab">API tokens<span class="tab-count">{{ $tokens->count() }}</span></a><a href="#webhooks" data-tab="webhooks" role="tab">Webhooks<span class="tab-count">{{ $webhooks->count() }}</span></a></div>

<div data-panel="tokens" class="stack">
    <x-card title="Your tokens" :flush="true">
        <x-slot:actions><span class="help">Base URL <code>{{ $apiUrl }}</code></span></x-slot:actions>
        <div>
        @if ($tokens->isEmpty())<x-empty icon="key" title="No tokens yet" text="Create a token to call the admin API from scripts and your own apps. Each token acts as you.">@if (auth()->user()->isAdmin())<a class="btn primary" href="#new-token">Create a token</a>@endif</x-empty>@else
        <table class="t stack-sm"><thead><tr><th>Name</th><th>Token</th><th>Access</th><th>Last used</th><th>Expires</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody>
            @foreach ($tokens as $t)
            @php($expired = $t->isExpired())
            <tr>
                <td data-label="Name"><b>{{ $t->name }}</b><div class="muted small">Created {{ $t->created_at?->format('j M Y') }}</div></td>
                <td data-label="Token" class="mono">{{ $t->prefix }}…</td>
                <td data-label="Access">@if($t->abilities === null)<span class="pill brand">Full access</span>@else
                    @php($groups = collect($t->abilities)->groupBy(fn ($a) => explode('.', $a)[0])->map(fn ($l, $g) => ucfirst(str_replace('-', ' ', $g)).' ('.$l->map(fn ($a) => explode('.', $a)[1] ?? $a)->implode('/').')'))
                    <span class="muted small" title="{{ $groups->implode(', ') }}">{{ $groups->take(2)->implode(', ') }}@if ($groups->count() > 2) +{{ $groups->count() - 2 }} more @endif @if ($groups->isEmpty()) No abilities @endif</span>@endif</td>
                <td data-label="Last used" class="muted">{{ $t->last_used_at?->diffForHumans() ?? 'Never used' }}</td>
                <td data-label="Expires">@if ($expired)<span class="pill bad">Expired</span>@else<span class="muted">{{ $t->expires_at?->format('j M Y') ?? 'Never' }}</span>@endif</td>
                <td class="right"><form method="POST" action="{{ route('admin.api.tokens.destroy', $t) }}" data-confirm="Revoke the token &quot;{{ $t->name }}&quot;? Anything using it stops working immediately." data-danger data-confirm-label="Revoke token">@csrf @method('DELETE')<button class="btn sm ghost danger" type="submit">Revoke</button></form></td>
            </tr>@endforeach
        </tbody></table>@endif
        </div>
    </x-card>
    @if (auth()->user()->isAdmin())
    <form id="new-token" class="card setting form-col" method="POST" action="{{ route('admin.api.tokens.store') }}" novalidate novalidate>
        @csrf
        <div class="setting-h"><h2>Create a token</h2><p>Tokens act as you. Give each integration its own token and only the access it needs.</p></div>
        <div class="setting-b">
            <x-system-field name="name" label="Name" placeholder="e.g. Accounting sync" required help="So you can recognise it later in this list." />
            <x-system-field name="scope" label="Access" type="select" :options="['full' => 'Full access (everything I can do)', 'custom' => 'Only the abilities I tick below']" />
            <fieldset class="fieldset"><legend>Abilities <span class="opt">(used when access is restricted)</span></legend>
                <div class="perm-grid">@foreach (collect(array_keys($abilityCatalog))->groupBy(fn ($a) => explode('.', $a)[0]) as $r => $list)
                    <div class="perm-group"><b>{{ str_replace('-', ' ', $r) }}</b>
                    @foreach ($list as $a)<label class="check" title="{{ $abilityCatalog[$a] }}"><input type="checkbox" name="abilities[]" value="{{ $a }}"> {{ ucfirst(explode('.', $a)[1]) }}</label>@endforeach</div>@endforeach</div>
            </fieldset>
            <x-system-field name="expires_in" label="Expires after" type="number" :optional="true" placeholder="Never" suffix="days" help="Leave blank for a token that never expires." min="1" max="3650" />
        </div>
        <div class="setting-f"><span class="help">After you create it, copy the token from the top of the page. It is shown only once.</span><button class="btn primary sm" type="submit">Create token</button></div>
    </form>
    @endif
</div>

<div data-panel="webhooks" class="stack hidden">
    <x-card title="Endpoints" :flush="true">
        <x-slot:actions><span class="help">We send signed JSON to each URL</span></x-slot:actions>
        <div>
        @if ($webhooks->isEmpty())<x-empty icon="webhook" title="No webhooks yet" text="Get a signed POST to your URL whenever an invoice is paid, a service is activated, and more.">@if (auth()->user()->isAdmin())<a class="btn primary" href="#new-webhook">Add a webhook</a>@endif</x-empty>@else
        <table class="t stack-sm"><thead><tr><th>Endpoint</th><th>Events</th><th>Status</th><th>Last delivery</th><th class="right">Deliveries</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody>
            @foreach ($webhooks as $w)
            @php($last = $w->lastDelivery)
            <tr>
                <td data-label="Endpoint"><a class="link-underline" href="{{ route('admin.api.webhooks.show', $w) }}">{{ \Illuminate\Support\Str::limit($w->url, 60) }}</a>@if($w->description)<div class="muted small">{{ $w->description }}</div>@endif</td>
                <td data-label="Events" class="muted small">{{ in_array('*', $w->events ?? ['*']) ? 'All events' : count($w->events).' '.\Illuminate\Support\Str::plural('event', count($w->events)) }}</td>
                <td data-label="Status">@if($w->is_active)<span class="pill good">Active</span>@else<span class="pill">Paused</span>@endif</td>
                <td data-label="Last delivery">@if ($last)@if ($last->success)<span class="pill good">{{ $last->status_code ?: 'OK' }}</span>@else<span class="pill bad">{{ $last->status_code ?: 'Failed' }}</span>@endif <span class="muted small">{{ $last->created_at->diffForHumans() }}</span>@else<span class="muted">None yet</span>@endif</td>
                <td data-label="Deliveries" class="right">{{ number_format($w->deliveries_count) }}</td>
                <td class="right"><div class="row end"><form method="POST" action="{{ route('admin.api.webhooks.test', $w) }}">@csrf<button class="btn sm" type="submit">Send test</button></form><a class="btn sm ghost" href="{{ route('admin.api.webhooks.show', $w) }}">Manage</a></div></td>
            </tr>@endforeach
        </tbody></table>@endif
        </div>
    </x-card>
    @if (auth()->user()->isAdmin())
    <form id="new-webhook" class="card setting form-col" method="POST" action="{{ route('admin.api.webhooks.store') }}" novalidate>
        @csrf
        <div class="setting-h"><h2>Add a webhook</h2><p>Each request is signed with HMAC-SHA256 so you can verify it came from here.</p></div>
        <div class="setting-b">
            <x-system-field name="url" label="Endpoint URL" type="url" placeholder="https://example.com/hooks/billing" required />
            <x-system-field name="description" label="Description" :optional="true" />
            <fieldset class="fieldset"><legend>Events <span class="opt">(none ticked = all events)</span></legend>
                <div class="grid2">@foreach ($events as $name => $desc)<label class="check"><input type="checkbox" name="events[]" value="{{ $name }}"> <span><span class="mono">{{ $name }}</span> <span class="muted small">· {{ $desc }}</span></span></label>@endforeach</div>
            </fieldset>
        </div>
        <div class="setting-f"><span class="help">The signing secret is shown once after you add it.</span><button class="btn primary sm" type="submit">Add webhook</button></div>
    </form>
    @endif
</div>

@endsection
