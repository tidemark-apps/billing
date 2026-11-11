@if ($service->isAddon())
<div class="notice"><x-icon name="info" :size="16" /><div>This is an add-on to <a class="link-underline" href="{{ route('admin.services.show', $service->parent_service_id) }}">{{ $service->parent?->title() }}</a>. It bills on that service's renewal date and ends with it.</div></div>
@elseif ($addons->isNotEmpty() || $addonOptions->isNotEmpty())
<x-card title="Add-ons">
    @if ($addons->isNotEmpty())
    <dl class="kv wide">
        @foreach ($addons as $a)<dt><a class="link-underline" href="{{ route('admin.services.show', $a) }}">{{ $a->product?->name ?? 'Add-on' }}</a></dt><dd><x-status :status="$a->status" /> · {{ money($a->amount, $a->currency) }} {{ $a->billing_cycle->suffix() }}</dd>@endforeach
    </dl>
    @endif
    @if ($addonOptions->isNotEmpty())
    <form method="POST" action="{{ route('admin.services.addons.store', $service) }}" class="row mt-16">@csrf
        <select name="product_id" aria-label="Add-on" required>
            @foreach ($addonOptions as $o)<option value="{{ $o['product']->id }}">{{ $o['product']->name }} · {{ money($o['amount'], $service->currency) }} {{ $service->billing_cycle->suffix() }}@if ($o['preview']['trial']) (free until the trial ends)@elseif ($o['preview']['charge'] > 0) (invoice {{ money($o['preview']['charge'], $service->currency) }} now)@endif</option>@endforeach
        </select>
        <button class="btn" type="submit">Add</button>
    </form>
    @endif
</x-card>
@endif
