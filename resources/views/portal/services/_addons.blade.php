@if (! $service->isAddon() && ($addons->isNotEmpty() || $addonOptions->isNotEmpty()))
<x-card title="Add-ons">
    @foreach ($addons as $a)
    <div class="row spread"><span><a class="link-underline" href="{{ route('portal.services.show', $a) }}">{{ $a->product?->name }}</a> <x-status :status="$a->status" /></span><span class="muted">{{ money($a->amount, $a->currency) }} {{ $a->billing_cycle->suffix() }}</span></div>
    @endforeach
    @foreach ($addonOptions as $o)
    <form method="POST" action="{{ route('portal.services.addons.store', $service) }}" class="row spread mt-16" data-confirm="Add {{ $o['product']->name }}?">@csrf
        <input type="hidden" name="product_id" value="{{ $o['product']->id }}">
        <span><b>{{ $o['product']->name }}</b> · {{ money($o['amount'], $service->currency) }} {{ $service->billing_cycle->suffix() }}
            <span class="help">@if ($o['preview']['trial'])Free until your trial ends.@elseif ($o['preview']['charge'] > 0)You pay {{ money($o['preview']['charge'], $service->currency) }} now for the rest of this period, then it renews with the service.@else Starts now and renews with the service.@endif</span></span>
        <button class="btn" type="submit">Add</button>
    </form>
    @endforeach
</x-card>
@elseif ($service->isAddon())
<div class="notice"><x-icon name="info" :size="16" /><div>This is an add-on to <a class="link-underline" href="{{ route('portal.services.show', $service->parent_service_id) }}">{{ $service->parent?->title() }}</a>. It renews with that service.</div></div>
@endif
