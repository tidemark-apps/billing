@php($left = (int) max(0, now()->startOfDay()->diffInDays($service->trial_ends_at, false)))
<x-card title="Free trial">
    <dl class="kv wide">
        <dt>Trial ends</dt><dd>{{ $service->trial_ends_at->format('j M Y') }} <span class="muted small">({{ $left }} {{ Str::plural('day', $left) }} left)</span></dd>
        <dt>Then</dt><dd>{{ money($service->amount, $service->currency) }} {{ $service->billing_cycle->suffix() }}, invoiced when the trial ends</dd>
    </dl>
    <div class="row wrap" style="margin-top:16px">
        <form method="POST" action="{{ route('admin.services.trial.extend', $service) }}" class="row">@csrf
            <input type="number" name="days" min="1" max="365" value="7" aria-label="Days to extend" style="width:90px">
            <button class="btn" type="submit">Extend trial</button>
        </form>
        <form method="POST" action="{{ route('admin.services.trial.end', $service) }}" data-confirm="End the trial now and create the first invoice?" data-confirm-label="End trial">@csrf
            <button class="btn primary" type="submit">End trial and invoice now</button>
        </form>
    </div>
    @error('days')<span class="field-error">{{ $message }}</span>@enderror
</x-card>
