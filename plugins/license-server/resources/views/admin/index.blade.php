@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Licenses')
@section('content')
<x-page-head title="Licenses" sub="Keys issued by the License Server plugin." />
<x-card title="Verification key">
    <p class="help" style="margin-bottom:8px">Embed this Ed25519 public key in your software to verify licence responses offline. Endpoints: <code>{{ url('/api/v1/x/license-server') }}/verify | activate | deactivate | public-key</code></p>
    <div class="secret-box"><span class="grow">{{ $publicKey }}</span><button class="btn sm" type="button" data-copy="{{ $publicKey }}">Copy</button></div>
</x-card>
<form class="filters" method="GET">
    <div class="searchbox"><x-icon name="search" :size="15" /><input name="q" value="{{ $q }}" placeholder="Search key, product, client…"></div>
    <select name="status" onchange="this.form.submit()">@foreach (['' => 'All statuses', 'active' => 'Active', 'suspended' => 'Suspended', 'revoked' => 'Revoked'] as $k => $v)<option value="{{ $k }}" @selected($status === $k)>{{ $v }}</option>@endforeach</select>
    <button class="btn" type="submit">Filter</button>
</form>
<x-card :flush="true">
    @if ($licenses->isEmpty())
        <x-empty icon="key" title="No licences yet" text="Create a product with the “Software licence” module. A licence is issued when an order is paid." />
    @else
    <div class="tscroll"><table class="t">
        <thead><tr><th>Key</th><th>Product</th><th>Client</th><th>Status</th><th class="right">Activations</th><th>Expires</th></tr></thead>
        <tbody>@foreach ($licenses as $l)
            <tr class="click" onclick="location.href='{{ route('admin.license-server.show', $l) }}'">
                <td><a class="mono" href="{{ route('admin.license-server.show', $l) }}">{{ $l->key }}</a></td>
                <td>{{ $l->product_code }}</td>
                <td>{{ $l->service?->client?->displayName() ?? '—' }}</td>
                <td><span class="pill {{ ['active' => 'good', 'grace' => 'warn', 'suspended' => 'warn', 'revoked' => 'bad', 'expired' => 'bad'][$l->effectiveStatus()] ?? '' }}">{{ ucfirst($l->effectiveStatus()) }}</span></td>
                <td class="right">{{ $l->activations_count }} / {{ $l->max_activations }}</td>
                <td>{{ $l->expires_at?->format('j M Y') ?? 'Follows billing' }}</td>
            </tr>
        @endforeach</tbody>
    </table></div>
    {{ $licenses->links() }}
    @endif
</x-card>
@endsection
