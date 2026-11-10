@extends('layouts.app', ['area' => 'portal'])
@section('title', 'API access')
@section('content')
<x-page-head title="API access" sub="Build your own client area or automate orders with a personal token."><a class="btn" href="{{ url('/api/v1') }}" target="_blank" rel="noopener">API reference</a></x-page-head>
@if (session('new_token'))<div class="notice good"><x-icon name="key" :size="16" /><div class="grow"><b>Your new token</b><div class="secret-box mt-8"><span class="grow">{{ session('new_token') }}</span><button class="btn sm" type="button" data-copy="{{ session('new_token') }}">Copy</button></div></div></div>@endif
<x-setting title="Create a token" description="A token acts as you. It is shown once, so copy it when it appears." :action="route('portal.api-tokens.store')"><x-field name="name" label="Name" placeholder="e.g. My website" help="Helps you recognise it later." required /><x-slot:footer><button class="btn primary sm" type="submit">Create token</button></x-slot:footer></x-setting>
<x-card title="Your tokens" :flush="true">
@if ($tokens->isEmpty())<x-empty icon="key" title="No tokens yet" text="Create one above to use the API." />@else
<div class="tscroll"><table class="t"><thead><tr><th>Name</th><th>Token</th><th>Last used</th><th></th></tr></thead><tbody>
@foreach ($tokens as $t)<tr><td>{{ $t->name }}</td><td class="mono">{{ $t->prefix }}…</td><td>{{ $t->last_used_at?->diffForHumans() ?? 'Never' }}</td><td class="right"><form method="POST" action="{{ route('portal.api-tokens.destroy', $t) }}" data-confirm="Revoke token {{ $t->name }}? Anything using it will stop working." data-confirm-label="Revoke token" data-danger>@csrf @method('DELETE')<button class="btn sm danger" type="submit">Revoke</button></form></td></tr>@endforeach
</tbody></table></div>@endif
</x-card>
@endsection
