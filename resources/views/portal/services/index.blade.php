@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Services')
@section('content')
<x-page-head title="Services" sub="Everything you have ordered, what it costs and when it renews."><a class="btn primary" href="{{ route('portal.store') }}">Order services</a></x-page-head>
<x-view-tabs :tabs="['' => 'All', 'active' => 'Active', 'pending' => 'Pending', 'suspended' => 'Suspended', 'ended' => 'Cancelled']" :current="$status" :counts="$counts" param="status" label="Service status" />
<x-card :flush="true">
@if ($services->isEmpty())
    @if ($status === '')<x-empty icon="boxes" title="No services yet" text="Your services will appear here once you order."><a class="btn sm" href="{{ route('portal.store') }}">Browse services</a></x-empty>
    @else<x-empty icon="boxes" title="No services in this tab" text="Nothing matches this status."><a class="btn sm" href="{{ route('portal.services.index') }}">Show all services</a></x-empty>@endif
@else
<div class="tscroll"><table class="t stack-sm"><thead><tr><th>Service</th><th>Status</th><th>Billing</th><th>Next due</th><th class="right">Price</th></tr></thead><tbody>
@foreach ($services as $s)
<tr><td data-label="Service"><a href="{{ route('portal.services.show', $s) }}"><b>{{ $s->title() }}</b></a>@if ($s->cancel_at_period_end)<div class="muted small">Cancels at end of period</div>@endif</td><td data-label="Status"><x-status :status="$s->status" />@if ($s->onTrial()) <span class="pill">Trial</span>@endif</td><td data-label="Billing">{{ $s->billing_cycle->label() }}</td><td data-label="Next due">{{ $s->next_due_date?->format('j M Y') ?? '—' }}</td><td class="right num" data-label="Price">{{ money($s->amount, $s->currency) }} <span class="muted small">{{ $s->billing_cycle->suffix() }}</span></td></tr>
@endforeach</tbody></table></div>
<div class="pager-wrap">{{ $services->links() }}</div>
@endif
</x-card>
@endsection
