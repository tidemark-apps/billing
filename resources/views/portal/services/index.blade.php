@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Services')
@section('content')
<x-page-head title="Services"><a class="btn primary" href="{{ route('portal.store') }}">Order services</a></x-page-head>
<x-card :flush="true">
@if ($services->isEmpty())<x-empty icon="boxes" title="No services yet" text="Your services will appear here once you order."/>@else
<table class="t stack-sm"><thead><tr><th>Service</th><th>Status</th><th>Billing</th><th>Next due</th><th class="right">Price</th></tr></thead><tbody>
@foreach ($services as $s)
<tr><td data-label="Service"><a href="{{ route('portal.services.show', $s) }}"><b>{{ $s->title() }}</b></a></td><td data-label="Status"><x-status :status="$s->status" /></td><td data-label="Billing">{{ $s->billing_cycle->label() }}</td><td data-label="Next due">{{ $s->next_due_date?->format('j M Y') ?? '—' }}</td><td class="right" data-label="Price">{{ money($s->amount, $s->currency) }}</td></tr>
@endforeach</tbody></table>
{{ $services->links() }}
@endif
</x-card>
@endsection
