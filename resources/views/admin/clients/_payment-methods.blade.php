@php $__methods = \App\Models\PaymentMethod::active()->where('client_id', $client->id)->orderByDesc('is_default')->orderBy('id')->get(); @endphp
<div data-panel="methods" class="hidden"><x-card :flush="true">
    @if ($__methods->isEmpty())<x-empty icon="credit-card" title="No saved payment methods" text="Cards and mandates the client saves at checkout or in their client area appear here." />@else
    <table class="t stack-sm"><thead><tr><th>Method</th><th>Gateway</th><th>Expires</th><th>Autopay</th><th>Last used</th><th></th></tr></thead><tbody>
    @foreach ($__methods as $m)<tr>
        <td data-label="Method">{{ $m->displayName() }} @if ($m->is_default)<span class="pill good">Default</span>@endif</td><td>{{ ucfirst($m->gateway) }}</td><td>{{ $m->expiry() ?? '—' }}</td>
        <td><span class="pill {{ $m->autopay ? 'good' : '' }}">{{ $m->autopay ? 'On' : 'Off' }}</span></td><td>{{ $m->last_used_at?->format('j M Y') ?? '—' }}</td>
        <td class="right"><form method="POST" action="{{ route('admin.clients.payment-methods.destroy', [$client, $m->uuid]) }}" data-confirm="Remove {{ $m->displayName() }}? The customer will have to add it again." data-confirm-label="Remove" data-danger>@csrf @method('DELETE')<button class="btn sm danger" type="submit">Remove</button></form></td>
    </tr>@endforeach
    </tbody></table>@endif
</x-card></div>
