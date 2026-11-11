@if (! $service->isAddon() && ($addons->isNotEmpty() || $addonOptions->isNotEmpty()))
<section class="card svc-card">
    <h2>Add-ons</h2>
    <p class="svc-lead">Extras billed together with this plan.</p>
    @foreach ($addons as $a)
    <div class="svc-item">
        <div><b><a class="link-underline" href="{{ route('portal.services.show', $a) }}">{{ $a->product?->name }}</a></b> <x-status :status="$a->status" />
            <span class="svc-d">{{ money($a->amount, $a->currency) }} {{ $a->billing_cycle->suffix() }}</span></div>
        <a class="btn sm ghost" href="{{ route('portal.services.show', $a) }}">Manage</a>
    </div>
    @endforeach
    @foreach ($addonOptions as $o)
    <form method="POST" action="{{ route('portal.services.addons.store', $service) }}" class="svc-item" data-confirm="Add {{ $o['product']->name }}?">@csrf
        <input type="hidden" name="product_id" value="{{ $o['product']->id }}">
        <div><b>{{ $o['product']->name }}</b> <span class="svc-d">{{ money($o['amount'], $service->currency) }} {{ $service->billing_cycle->suffix() }}</span>
            @if (trim((string) $o['product']->description))<span class="svc-d">{{ $o['product']->description }}</span>@endif
            <span class="svc-d">@if ($o['preview']['trial'])Free until your trial ends.@elseif ($o['preview']['charge'] > 0)You pay {{ money($o['preview']['charge'], $service->currency) }} now for the rest of this period, then it renews with the service.@else Starts now and renews with the service.@endif</span></div>
        <button class="btn sm" type="submit">Add</button>
    </form>
    @endforeach
</section>
@elseif ($service->isAddon())
<div class="notice"><x-icon name="info" :size="16" /><div>This is an add-on to <a class="link-underline" href="{{ route('portal.services.show', $service->parent_service_id) }}">{{ $service->parent?->title() }}</a>. It renews with that service.</div></div>
@endif
