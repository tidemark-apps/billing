@if ($scheduledChange)
    <div class="notice warn center"><x-icon name="info" :size="16" /><div>Change to <b>{{ $scheduledChange->toProduct?->name }}</b> ({{ $scheduledChange->to_cycle->label() }}, {{ money($scheduledChange->to_amount, $scheduledChange->currency) }}) is scheduled for {{ $scheduledChange->effective_date->format('j M Y') }}.</div>
        <form class="alert-action" method="POST" action="{{ route('admin.services.plan-change.cancel', $service) }}">@csrf @method('DELETE')<button class="btn sm" type="submit">Cancel scheduled change</button></form></div>
@endif
@if ($canChangePlan)
<x-card title="Change plan">
    @if ($preview)
        <p class="muted small" style="margin-bottom:12px">Review the change, then confirm.</p>
        @include('portal.services._plan-preview', ['preview' => $preview, 'staff' => true])
        <form method="POST" action="{{ route('admin.services.plan-change.store', $service) }}" class="row" style="margin-top:16px">@csrf
            <input type="hidden" name="plan" value="{{ $selected['plan'] }}"><input type="hidden" name="timing" value="{{ $preview['timing'] }}">
            <button class="btn primary" type="submit">Confirm change</button><a class="btn" href="{{ route('admin.services.show', $service) }}">Back</a>
        </form>
    @elseif ($planOptions->isEmpty())
        <span class="muted">No other plans are available in {{ $service->currency }}.</span>
    @else
        <form method="POST" action="{{ route('admin.services.plan-change.preview', $service) }}" class="stack">@csrf
            <div class="grid2">
                <label><span>New plan</span>
                    <select name="plan" required>
                        @foreach ($planOptions->groupBy(fn ($o) => $o['product']->name) as $name => $opts)
                            <optgroup label="{{ $name }}">@foreach ($opts as $o)<option value="{{ $o['product']->id }}:{{ $o['cycle']->value }}" @selected(old('plan') === $o['product']->id.':'.$o['cycle']->value)>{{ $o['cycle']->label() }} · {{ money($o['amount'], $service->currency) }} {{ $o['cycle']->suffix() }}</option>@endforeach</optgroup>
                        @endforeach
                    </select>
                </label>
                <x-field name="timing" label="When" type="select" :options="['' => 'Default (upgrades now, downgrades at renewal)', 'immediate' => 'Immediately', 'renewal' => 'At next renewal']" />
            </div>
            <div><button class="btn primary" type="submit">Preview change</button></div>
        </form>
    @endif
</x-card>
@endif
@if ($history->isNotEmpty())
<x-card title="Plan changes" :flush="true">
    <table class="t stack-sm"><thead><tr><th>Date</th><th>Change</th><th>Timing</th><th class="right">Credit</th><th class="right">Charge</th><th>Invoice</th><th>Status</th></tr></thead><tbody>
    @foreach ($history as $c)
        <tr><td data-label="Date">{{ ($c->applied_at ?? $c->created_at)->format('j M Y') }}</td>
        <td data-label="Change">{{ $c->fromProduct?->name ?? '—' }} ({{ $c->from_cycle->label() }}) → {{ $c->toProduct?->name ?? '—' }} ({{ $c->to_cycle->label() }}) <span class="muted small">{{ ucfirst($c->direction) }} · {{ $c->source }}</span></td>
        <td data-label="Timing">{{ $c->timing === 'renewal' ? 'At renewal, '.$c->effective_date->format('j M Y') : 'Immediate' }}</td>
        <td class="right" data-label="Credit">{{ money($c->credit, $c->currency) }}</td><td class="right" data-label="Charge">{{ money($c->charge, $c->currency) }}</td>
        <td data-label="Invoice">@if ($c->invoice)<a href="{{ route('admin.invoices.show', $c->invoice) }}" style="text-decoration:underline">{{ $c->invoice->displayNumber() }}</a>@else—@endif</td>
        <td data-label="Status"><span class="pill {{ $c->status === 'applied' ? 'good' : ($c->status === 'scheduled' ? 'warn' : '') }}">{{ ucfirst($c->status) }}</span></td></tr>
    @endforeach</tbody></table>
</x-card>
@endif
