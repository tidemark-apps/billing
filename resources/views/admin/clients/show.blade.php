@extends('layouts.app', ['area' => 'admin'])
@section('title', $client->displayName())
@section('content')
<x-page-head :title="$client->displayName()" :sub="$client->email" :back="route('admin.clients.index')" backLabel="Clients">
    <a class="btn" href="{{ route('admin.clients.edit', $client) }}"><x-icon name="pencil" :size="15" /> Edit</a>
    @if (Route::has('admin.invoices.create'))<a class="btn" href="{{ route('admin.invoices.create', ['client' => $client->id]) }}">New invoice</a>@endif
    @if (Route::has('admin.quotes.create'))<a class="btn" href="{{ route('admin.quotes.create', ['client' => $client->id]) }}">New quote</a>@endif
    @if ($loginUser)<form method="POST" action="{{ route('admin.impersonate.start', $loginUser) }}">@csrf<button class="btn" type="submit"><x-icon name="eye" :size="15" /> View as client</button></form>@endif
    @if (auth()->user()->isAdmin())<form method="POST" action="{{ route('admin.clients.destroy', $client) }}" data-confirm="Delete {{ $client->displayName() }}? This can't be undone from the UI." data-confirm-label="Delete" data-danger>@csrf @method('DELETE')<button class="btn danger" type="submit">Delete</button></form>@endif
</x-page-head>
<div class="tabs page-tabs" data-tabs role="tablist">
    @foreach (['overview' => 'Overview', 'services' => 'Services', 'invoices' => 'Invoices', 'quotes' => 'Quotes', 'payments' => 'Payments', 'methods' => 'Payment methods', 'contacts' => 'Contacts', 'notes' => 'Notes'] as $id => $label)<a href="#{{ $id }}" data-tab="{{ $id }}" role="tab">{{ $label }}</a>@endforeach
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
            <dt>Status</dt><dd><span class="pill {{ $client->status === 'active' ? 'good' : '' }}">{{ ucfirst($client->status) }}</span></dd>
            <dt>Client since</dt><dd>{{ $client->created_at->format('j M Y') }}</dd>
        </dl></x-card>
        <x-card title="Address">@forelse ($client->addressLines() as $l)<div>{{ $l }}</div>@empty<span class="muted">No address on file.</span>@endforelse</x-card>
    </div>
    <div class="stats">
        <x-stat label="Live services" :value="$client->services->whereIn('status', [\App\Enums\ServiceStatus::Active, \App\Enums\ServiceStatus::Suspended])->count()" />
        <x-stat label="Open invoices" :value="$client->invoices->where('status', \App\Enums\InvoiceStatus::Open)->count()" />
        <x-stat label="Lifetime paid" :value="money($client->payments->where('status', \App\Enums\PaymentStatus::Completed)->sum(fn ($p) => $p->isRefund() ? -$p->amount : $p->amount), $client->currency)" />
    </div>
    {!! extensions('admin.client', ['client' => $client]) !!}
</div>
<div data-panel="services" class="hidden"><x-card :flush="true">
    @if ($client->services->isEmpty())<x-empty icon="boxes" title="No services" />@else
    <table class="t"><thead><tr><th>Service</th><th>Status</th><th>Cycle</th><th class="right">Price</th><th>Next due</th></tr></thead><tbody>
    @foreach ($client->services as $s)<tr><td><a href="{{ route('admin.services.show', $s) }}">{{ $s->title() }}</a></td><td><x-status :status="$s->status" />@if ($s->onTrial()) <span class="pill">Trial</span>@endif</td><td>{{ $s->billing_cycle->label() }}</td><td class="right">{{ money($s->amount, $s->currency) }}</td><td>{{ $s->next_due_date?->format('j M Y') ?? '—' }}</td></tr>@endforeach
    </tbody></table>@endif
</x-card><div style="margin-top:12px"><a class="btn sm" href="{{ route('admin.services.create', ['client' => $client->id]) }}">Add service</a></div></div>
<div data-panel="invoices" class="hidden"><x-card :flush="true">
    @if ($client->invoices->isEmpty())<x-empty icon="receipt" title="No invoices" />@else
    <table class="t"><thead><tr><th>Number</th><th>Status</th><th>Due</th><th class="right">Total</th></tr></thead><tbody>
    @foreach ($client->invoices as $i)<tr><td>@if (Route::has('admin.invoices.show'))<a href="{{ route('admin.invoices.show', $i) }}">{{ $i->displayNumber() }}</a>@else{{ $i->displayNumber() }}@endif</td><td><x-status :status="$i->status" /></td><td>{{ $i->due_date?->format('j M Y') }}</td><td class="right">{{ money($i->total, $i->currency) }}</td></tr>@endforeach
    </tbody></table>@endif
</x-card></div>
<div data-panel="quotes" class="hidden"><x-card :flush="true">
    @if ($client->quotes->isEmpty())<x-empty icon="file-text" title="No quotes" />@else
    <table class="t"><thead><tr><th>Number</th><th>Status</th><th>Valid until</th><th class="right">Total</th></tr></thead><tbody>
    @foreach ($client->quotes as $q)<tr><td>@if (Route::has('admin.quotes.show'))<a href="{{ route('admin.quotes.show', $q) }}">{{ $q->displayNumber() }}</a>@else{{ $q->displayNumber() }}@endif</td><td><x-status :status="$q->status" /></td><td>{{ $q->valid_until?->format('j M Y') }}</td><td class="right">{{ money($q->total, $q->currency) }}</td></tr>@endforeach
    </tbody></table>@endif
</x-card></div>
<div data-panel="payments" class="hidden"><x-card :flush="true">
    @if ($client->payments->isEmpty())<x-empty icon="wallet" title="No payments" />@else
    <table class="t"><thead><tr><th>Date</th><th>Method</th><th>Status</th><th class="right">Amount</th></tr></thead><tbody>
    @foreach ($client->payments as $p)<tr><td>{{ $p->paid_at?->format('j M Y') }}</td><td>{{ $p->isRefund() ? 'Refund · ' : '' }}{{ ucfirst($p->gateway) }}</td><td><x-status :status="$p->status" /></td><td class="right">{{ $p->isRefund() ? '−' : '' }}{{ money($p->amount, $p->currency) }}</td></tr>@endforeach
    </tbody></table>@endif
</x-card></div>
@include('admin.clients._payment-methods')
<div data-panel="contacts" class="hidden stack">
    <x-card title="Logins" :flush="true"><table class="t"><thead><tr><th>Name</th><th>Email</th><th>Last sign-in</th><th>Active</th><th>2FA</th></tr></thead><tbody>
        @forelse ($client->users as $u)<tr><td>{{ $u->name }}</td><td>{{ $u->email }}</td><td>{{ $u->last_login_at?->diffForHumans() ?? 'Never' }}</td><td>{{ $u->is_active ? 'Yes' : 'No' }}</td><td>@if ($u->hasTwoFactor())<form method="POST" action="{{ route('admin.users.two-factor.reset', $u) }}" class="row" data-confirm="Reset two-factor authentication for {{ $u->email }}? They can sign in with just their password until they set it up again." data-confirm-label="Reset" data-danger>@csrf<span class="pill good">On</span><label class="check"><input type="checkbox" name="revoke_tokens" value="1"> Revoke API tokens</label><button class="btn sm" type="submit">Reset</button></form>@else<span class="muted">Off</span>@endif</td></tr>@empty<tr><td colspan="5" class="muted">No logins yet.</td></tr>@endforelse
    </tbody></table></x-card>
    <form method="POST" action="{{ route('admin.clients.contacts.store', $client) }}" class="card setting">@csrf
        <div class="setting-h"><h2>Add a login</h2><p>Gives another person access to this client's client area.</p></div>
        <div class="setting-b"><div class="grid3"><x-field name="name" label="Name" required /><x-field name="email" label="Email" type="email" required /><x-field name="password" label="Password" type="password" required autocomplete="new-password" /></div></div>
        <div class="setting-f"><span></span><button class="btn primary sm" type="submit">Add login</button></div>
    </form>
</div>
<div data-panel="notes" class="hidden"><x-card title="Internal notes">@if ($client->notes)<p style="white-space:pre-line">{{ $client->notes }}</p>@else<span class="muted">No notes. Add some from Edit.</span>@endif</x-card></div>
@endsection
