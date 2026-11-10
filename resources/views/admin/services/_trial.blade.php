@php
    $left = (int) max(0, now()->startOfDay()->diffInDays($service->trial_ends_at, false));
    $total = max(1, (int) $service->trial_ends_at->copy()->startOfDay()->diffInDays(($service->activated_at ?? $service->created_at)->copy()->startOfDay()));
    $pct = max(0, min(100, round((1 - $left / $total) * 100)));
@endphp
<x-card title="Free trial">
    <div class="row spread wrap">
        <div><div><b>{{ $left }}</b> {{ Str::plural('day', $left) }} left</div><div class="help">Ends {{ $service->trial_ends_at->format('j M Y') }}. Then {{ money($service->amount, $service->currency) }} {{ $service->billing_cycle->suffix() }}, invoiced when the trial ends.</div></div>
        <div class="row wrap">
            <form method="POST" action="{{ route('admin.services.trial.extend', $service) }}" class="row">@csrf
                <label class="sr-only" for="trial-days">Days to extend</label>
                <input id="trial-days" class="w-90" type="number" name="days" min="1" max="365" value="7">
                <button class="btn" type="submit">Extend by days</button>
            </form>
            <form method="POST" action="{{ route('admin.services.trial.end', $service) }}" data-confirm="End the trial for {{ $service->title() }} now and create the first invoice for {{ money($service->amount, $service->currency) }}?" data-confirm-label="End trial and invoice">@csrf
                <button class="btn" type="submit">End trial and invoice now</button>
            </form>
        </div>
    </div>
    <progress class="mt-16 w-full" max="100" value="{{ $pct }}" aria-label="Trial elapsed">{{ $pct }}%</progress>
    @error('days')<span class="field-error">{{ $message }}</span>@enderror
</x-card>
