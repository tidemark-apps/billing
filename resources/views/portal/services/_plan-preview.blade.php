{{-- A plan change worked out but not made yet. Expects $preview (PlanChangeService::preview) and optional $staff. --}}
@php($cur = $preview['currency'])
<dl class="kv wide">
    <dt>From</dt><dd>{{ $preview['from_product']->name }} · {{ $preview['from_cycle']->label() }} · {{ money($preview['from_amount'], $cur) }} {{ $preview['from_cycle']->suffix() }}</dd>
    <dt>To</dt><dd><b>{{ $preview['to_product']->name }} · {{ $preview['to_cycle']->label() }} · {{ money($preview['to_amount'], $cur) }} {{ $preview['to_cycle']->suffix() }}</b></dd>
    <dt>Takes effect</dt><dd>{{ $preview['timing'] === 'renewal' ? 'At renewal, ' : 'Today, ' }}{{ $preview['effective_date']->format('j M Y') }}</dd>
    @if ($preview['timing'] === 'immediate' && ! $preview['trial'])
        <dt>Credit for unused time</dt><dd>{{ money($preview['credit'], $cur) }}</dd>
        <dt>Charge for the new plan</dt><dd>{{ money($preview['charge'], $cur) }}</dd>
        <dt>Amount due now</dt><dd><b>{{ money($preview['amount_due'], $cur) }}</b> <span class="muted small">(before tax)</span></dd>
        <dt>Next renewal</dt><dd>{{ $preview['next_due_date']->format('j M Y') }}</dd>
    @else
        <dt>Amount due now</dt><dd><b>{{ money(0, $cur) }}</b></dd>
    @endif
</dl>
@if ($preview['timing'] === 'renewal')
    <p class="help mt-8">The change is scheduled, nothing is charged now{{ $preview['direction'] === 'downgrade' ? ', and you keep your current plan until then. Downgrades apply at the next renewal' : '' }}. The new price applies from the renewal invoice.</p>
@elseif ($preview['trial'])
    <p class="help mt-8">Nothing has been paid yet, so there is nothing to prorate. The trial carries on with the new plan.</p>
@endif
@if ($preview['forfeited'] > 0)
    <div class="notice warn mt-12"><x-icon name="info" :size="16" /><div>The credit ({{ money($preview['credit'], $cur) }}) is larger than the new charge. The unused {{ money($preview['forfeited'], $cur) }} is not refunded or carried forward.</div></div>
@endif
