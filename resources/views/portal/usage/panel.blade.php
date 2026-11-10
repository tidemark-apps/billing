@php($usageSvc = app(\App\Services\UsageService::class))
@if ($usageSvc->hasMeters($service))
@php($summary = $usageSvc->currentPeriodSummary($service))
<x-card title="Usage this period">
    @if ($summary->lines->isEmpty())
        <span class="muted">No usage recorded yet.</span>
    @else
        <table class="t"><thead><tr><th>Meter</th><th class="right">Used</th><th class="right">Included</th><th class="right">Estimated cost</th></tr></thead><tbody>
        @foreach ($summary->lines as $line)
            <tr><td>{{ $line->meter->name }}</td><td class="right">{{ \App\Support\UsageMath::display($line->quantity) }} {{ $line->meter->unit }}</td><td class="right">{{ \App\Support\UsageMath::display($line->included) }}</td><td class="right">{{ money($line->amount, $service->currency) }}</td></tr>
        @endforeach
        <tr><td colspan="3"><b>Estimated usage charge</b></td><td class="right"><b>{{ money($summary->total(), $service->currency) }}</b></td></tr>
        </tbody></table>
    @endif
    <p class="muted small mt-8">Usage is billed after the period ends, together with your renewal invoice. Estimate excludes tax.</p>
</x-card>
@endif
