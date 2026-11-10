@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Payment methods')
@section('content')
<x-page-head title="Payment methods" sub="Save a card or wallet at our payment provider to pay invoices faster, or have them paid automatically on the due date." />
<x-card title="Saved methods" :flush="true">
@if ($methods->isEmpty())<x-empty icon="credit-card" title="No saved payment methods" text="Add one below to pay faster or turn on automatic payment." />@else
<div class="tscroll"><table class="t"><thead><tr><th>Method</th><th>Expires</th><th>Automatic payment</th><th></th></tr></thead><tbody>
@foreach ($methods as $m)
<tr>
    <td><b>{{ $m->displayName() }}</b> @if ($m->is_default)<span class="pill good">Default</span>@endif @if ($m->isExpired())<span class="pill bad">Expired</span>@endif<div class="muted small">{{ $titles[$m->gateway] ?? ucfirst($m->gateway) }}</div></td>
    <td>{{ $m->expiry() ?? '—' }}</td>
    <td><form method="POST" action="{{ route('portal.payment-methods.autopay', $m->uuid) }}" class="row">@csrf
        <input type="hidden" name="autopay" value="{{ $m->autopay ? 0 : 1 }}">
        <span class="pill {{ $m->autopay ? 'good' : '' }}">{{ $m->autopay ? 'On' : 'Off' }}</span>
        <button class="btn sm" type="submit">{{ $m->autopay ? 'Turn off' : 'Turn on' }}</button></form></td>
    <td class="right"><div class="row end">
        @unless ($m->is_default)<form method="POST" action="{{ route('portal.payment-methods.default', $m->uuid) }}">@csrf<button class="btn sm" type="submit">Make default</button></form>@endunless
        <form method="POST" action="{{ route('portal.payment-methods.destroy', $m->uuid) }}" data-confirm="Remove {{ $m->displayName() }}? It can no longer be used to pay invoices{{ $m->autopay ? ' and automatic payments will stop' : '' }}." data-confirm-label="Remove method" data-danger>@csrf @method('DELETE')<button class="btn sm danger" type="submit">Remove</button></form>
    </div></td>
</tr>
@endforeach
</tbody></table></div>@endif
</x-card>
@if ($gateways)
<form method="POST" action="{{ route('portal.payment-methods.setup') }}" class="card setting">@csrf
    <div class="setting-h"><h2>Add a payment method</h2><p>You will be sent to the provider's secure page. We never see or store your card number.</p></div>
    <div class="setting-b stack sm">
        <label class="check"><input type="checkbox" name="autopay" value="1" checked> Use it to pay my invoices automatically on the due date. If a payment fails we will email you and retry a few times.</label>
    </div>
    <div class="setting-f"><span class="muted small">You can turn automatic payment off at any time.</span>
        <div class="row">@foreach ($gateways as $key => $gw)<button class="btn {{ $loop->first ? 'primary' : '' }}" name="gateway" value="{{ $key }}" type="submit"><x-icon name="credit-card" :size="15" /> Add with {{ $titles[$key] ?? $gw->label() }}</button>@endforeach</div></div>
</form>
@else
<div class="notice"><x-icon name="info" :size="16" /><div>Saving payment methods isn't available yet. Please contact us.</div></div>
@endif
@endsection
