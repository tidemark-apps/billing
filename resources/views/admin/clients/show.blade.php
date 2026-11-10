@extends('layouts.app', ['area' => 'admin'])
@section('title', $client->displayName())
@section('content')
<x-page-head :title="$client->displayName()" :sub="$client->email" :back="route('admin.clients.index')" backLabel="Clients">
    @if (Route::has('admin.invoices.create'))<a class="btn primary" href="{{ route('admin.invoices.create', ['client' => $client->id]) }}"><x-icon name="plus" :size="15" /> New invoice</a>@endif
    <a class="btn" href="{{ route('admin.clients.edit', $client) }}"><x-icon name="pencil" :size="15" /> Edit</a>
    <x-menu label="More actions">
        @if (Route::has('admin.quotes.create'))<a class="item" role="menuitem" href="{{ route('admin.quotes.create', ['client' => $client->id]) }}"><span>New quote</span><x-icon name="file-text" :size="14" /></a>@endif
        <a class="item" role="menuitem" href="{{ route('admin.services.create', ['client' => $client->id]) }}"><span>Add service</span><x-icon name="boxes" :size="14" /></a>
        @if ($loginUser)<form class="m-0" method="POST" action="{{ route('admin.impersonate.start', $loginUser) }}">@csrf<button class="item" role="menuitem" type="submit"><span>View as client</span><x-icon name="eye" :size="14" /></button></form>@endif
    </x-menu>
</x-page-head>
<section class="card sumhead" aria-label="Client summary">
    <div class="sh-item"><span class="sh-k">Status</span><span class="sh-v"><span class="pill {{ $client->status === 'active' ? 'good' : '' }}">{{ ucfirst($client->status) }}</span>@if ($client->is_internal)<span class="pill brand" title="Services are free and this account is left out of reports">Internal</span>@endif</span><span class="sh-c">Client since {{ $client->created_at->format('j M Y') }}</span></div>
    <div class="sh-item"><span class="sh-k">Lifetime paid</span><span class="sh-v big">{{ money($lifetimePaid, $client->currency) }}</span><span class="sh-c">Net of refunds</span></div>
    <div class="sh-item"><span class="sh-k">Open balance</span><span class="sh-v big">{{ money($openBalance, $client->currency) }}</span><span class="sh-c">{{ $openCount }} open {{ \Illuminate\Support\Str::plural('invoice', $openCount) }}</span></div>
    <div class="sh-item"><span class="sh-k">Live services</span><span class="sh-v big">{{ $liveServices }}</span><span class="sh-c">Active or suspended</span></div>
</section>
@php($counts = ['services' => $client->services->count(), 'invoices' => $client->invoices->count(), 'quotes' => $client->quotes->count(), 'payments' => $client->payments->count(), 'contacts' => $client->users->count()])
<div class="tabs page-tabs" data-tabs role="tablist">
    @foreach (['overview' => 'Overview', 'services' => 'Services', 'invoices' => 'Invoices', 'quotes' => 'Quotes', 'payments' => 'Payments', 'methods' => 'Payment methods', 'contacts' => 'Contacts', 'notes' => 'Notes'] as $id => $label)<a href="#{{ $id }}" data-tab="{{ $id }}" role="tab">{{ $label }}@if (array_key_exists($id, $counts))<span class="tab-count">{{ $counts[$id] }}</span>@endif</a>@endforeach
</div>
<div data-panel="overview" class="stack">
    <div class="grid2">
        <x-card title="Details"><dl class="kv wide">
            <dt>Name</dt><dd>{{ $client->name }}</dd>
            @if ($client->company_name)<dt>Company</dt><dd>{{ $client->company_name }}</dd>@endif
            <dt>Email</dt><dd>{{ $client->email }}</dd>
            @if ($client->phone)<dt>Phone</dt><dd>{{ $client->phone }}</dd>@endif
            <dt>Currency</dt><dd>{{ $client->currency }}</dd>
            @if ($client->tax_id)<dt>Tax ID</dt><dd>{{ $client->tax_id }}</dd>@endif
        </dl></x-card>
        <x-card title="Address">@forelse ($client->addressLines() as $l)<div>{{ $l }}</div>@empty<span class="muted">No address on file.</span> <a href="{{ route('admin.clients.edit', $client) }}" class="link-underline">Add one</a>@endforelse</x-card>
    </div>
    {!! extensions('admin.client', ['client' => $client]) !!}
    @if (auth()->user()->isAdmin())
    <form method="POST" action="{{ route('admin.clients.destroy', $client) }}" class="card setting danger-zone" data-confirm="Delete {{ $client->displayName() }}? The client and its history are removed from the admin lists. This can't be undone from the UI." data-confirm-label="Delete client" data-danger>@csrf @method('DELETE')
        <div class="setting-h"><h2>Danger zone</h2><p>Deleting a client is only possible once they have no live services or unpaid invoices.</p></div>
        <div class="setting-f"><span class="help">Delete {{ $client->displayName() }}</span><button class="btn danger sm" type="submit">Delete client</button></div>
    </form>
    @endif
</div>
<div data-panel="services" class="hidden"><x-card :flush="true">
    @if ($client->services->isEmpty())<x-empty icon="boxes" title="No services yet" text="Add a service to start billing this client."><a class="btn primary sm" href="{{ route('admin.services.create', ['client' => $client->id]) }}">Add service</a></x-empty>@else
    <table class="t stack-sm"><thead><tr><th>Service</th><th>Status</th><th>Cycle</th><th class="right">Price</th><th>Next due</th></tr></thead><tbody>
    @foreach ($client->services as $s)<tr><td data-label="Service"><a href="{{ route('admin.services.show', $s) }}">{{ $s->title() }}</a></td><td data-label="Status">@if ($s->onTrial() && $s->status === \App\Enums\ServiceStatus::Active)<span class="pill warn">Trial</span>@else<x-status :status="$s->status" />@endif</td><td data-label="Cycle">{{ $s->billing_cycle->label() }}</td><td class="right" data-label="Price">{{ money($s->amount, $s->currency) }}</td><td data-label="Next due">{{ $s->next_due_date?->format('j M Y') ?? '—' }}</td></tr>@endforeach
    </tbody></table>@endif
</x-card>@if ($client->services->isNotEmpty())<div class="mt-12"><a class="btn sm" href="{{ route('admin.services.create', ['client' => $client->id]) }}">Add service</a></div>@endif</div>
<div data-panel="invoices" class="hidden"><x-card :flush="true">
    @if ($client->invoices->isEmpty())<x-empty icon="receipt" title="No invoices yet" text="Invoices appear when renewals come due, or you can create one by hand.">@if (Route::has('admin.invoices.create'))<a class="btn primary sm" href="{{ route('admin.invoices.create', ['client' => $client->id]) }}">New invoice</a>@endif</x-empty>@else
    <table class="t stack-sm"><thead><tr><th>Number</th><th>Status</th><th>Due</th><th class="right">Total</th></tr></thead><tbody>
    @foreach ($client->invoices as $i)<tr><td data-label="Number">@if (Route::has('admin.invoices.show'))<a href="{{ route('admin.invoices.show', $i) }}">{{ $i->displayNumber() }}</a>@else{{ $i->displayNumber() }}@endif</td><td data-label="Status">@if ($i->isOverdue())<span class="pill bad">Overdue</span>@else<x-status :status="$i->status" />@endif</td><td data-label="Due">{{ $i->due_date?->format('j M Y') ?? '—' }}</td><td class="right" data-label="Total">{{ money($i->total, $i->currency) }}</td></tr>@endforeach
    </tbody></table>@endif
</x-card></div>
<div data-panel="quotes" class="hidden"><x-card :flush="true">
    @if ($client->quotes->isEmpty())<x-empty icon="file-text" title="No quotes yet" text="Quotes you send this client are listed here.">@if (Route::has('admin.quotes.create'))<a class="btn primary sm" href="{{ route('admin.quotes.create', ['client' => $client->id]) }}">New quote</a>@endif</x-empty>@else
    <table class="t stack-sm"><thead><tr><th>Number</th><th>Status</th><th>Valid until</th><th class="right">Total</th></tr></thead><tbody>
    @foreach ($client->quotes as $q)<tr><td data-label="Number">@if (Route::has('admin.quotes.show'))<a href="{{ route('admin.quotes.show', $q) }}">{{ $q->displayNumber() }}</a>@else{{ $q->displayNumber() }}@endif</td><td data-label="Status"><x-status :status="$q->status" /></td><td data-label="Valid until">{{ $q->valid_until?->format('j M Y') ?? '—' }}</td><td class="right" data-label="Total">{{ money($q->total, $q->currency) }}</td></tr>@endforeach
    </tbody></table>@endif
</x-card></div>
<div data-panel="payments" class="hidden"><x-card :flush="true">
    @if ($client->payments->isEmpty())<x-empty icon="wallet" title="No payments yet" text="Payments and refunds for this client's invoices show up here." />@else
    <table class="t stack-sm"><thead><tr><th>Date</th><th>Method</th><th>Status</th><th class="right">Amount</th></tr></thead><tbody>
    @foreach ($client->payments as $p)<tr><td data-label="Date">{{ $p->paid_at?->format('j M Y') ?? '—' }}</td><td data-label="Method">{{ $p->isRefund() ? 'Refund · ' : '' }}{{ ucfirst($p->gateway) }}</td><td data-label="Status"><x-status :status="$p->status" /></td><td class="right" data-label="Amount">{{ $p->isRefund() ? '−' : '' }}{{ money($p->amount, $p->currency) }}</td></tr>@endforeach
    </tbody></table>@endif
</x-card></div>
@include('admin.clients._payment-methods')
<div data-panel="contacts" class="hidden stack">
    <x-card title="Logins" :flush="true"><table class="t stack-sm"><thead><tr><th>Name</th><th>Email</th><th>Last sign-in</th><th>Active</th><th>2FA</th></tr></thead><tbody>
        @forelse ($client->users as $u)<tr><td>{{ $u->name }}</td><td>{{ $u->email }}</td><td>{{ $u->last_login_at?->diffForHumans() ?? 'Never' }}</td><td>{{ $u->is_active ? 'Yes' : 'No' }}</td><td>@if ($u->hasTwoFactor())<form method="POST" action="{{ route('admin.users.two-factor.reset', $u) }}" class="row" data-confirm="Reset two-factor authentication for {{ $u->email }}? They can sign in with just their password until they set it up again." data-confirm-label="Reset" data-danger>@csrf<span class="pill good">On</span><label class="check"><input type="checkbox" name="revoke_tokens" value="1"> Revoke API tokens</label><button class="btn sm" type="submit">Reset</button></form>@else<span class="muted">Off</span>@endif</td></tr>@empty<tr><td colspan="5" class="muted">No logins yet.</td></tr>@endforelse
    </tbody></table></x-card>
    <form method="POST" action="{{ route('admin.clients.contacts.store', $client) }}" class="card setting">@csrf
        <div class="setting-h"><h2>Add a login</h2><p>Gives another person access to this client's client area.</p></div>
        <div class="setting-b"><div class="grid3"><x-field name="name" label="Name" required /><x-field name="email" label="Email" type="email" required /><x-field name="password" label="Password" type="password" required autocomplete="new-password" /></div></div>
        <div class="setting-f"><span></span><button class="btn primary sm" type="submit">Add login</button></div>
    </form>
</div>
<div data-panel="notes" class="hidden"><x-card title="Internal notes">@if ($client->notes)<p class="pre-line">{{ $client->notes }}</p>@else<span class="muted">No notes. Add some from Edit.</span>@endif</x-card></div>
@endsection
