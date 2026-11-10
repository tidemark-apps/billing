@php
    $usageSvc = app(\App\Services\UsageService::class);
    $meters = $usageSvc->meters($service);
@endphp
@if ($meters->isNotEmpty())
@php($summary = $usageSvc->currentPeriodSummary($service))
<x-card title="Metered usage">
    @error('usage')<div class="alert mb-16" role="alert">{{ $message }}</div>@enderror
    <p class="muted small mb-16">Unbilled usage{{ $summary->periodEnd ? ' for the period ending '.$summary->periodEnd->format('j M Y') : '' }}. It is invoiced in arrears with the renewal invoice.</p>
    @if ($summary->lines->isEmpty())
        <span class="muted">No unbilled usage yet. Record usage below, or let the API/plugins report it.</span>
    @else
        <table class="t stack-sm"><thead><tr><th>Meter</th><th class="right">Used</th><th class="right">Included</th><th class="right">Billable</th><th class="right">Estimated</th></tr></thead><tbody>
        @foreach ($summary->lines as $line)
            <tr><td data-label="Meter">{{ $line->meter->name }}</td><td class="right" data-label="Used">{{ \App\Support\UsageMath::display($line->quantity) }} {{ $line->meter->unit }}</td><td class="right" data-label="Included">{{ \App\Support\UsageMath::display($line->included) }}</td><td class="right" data-label="Billable">{{ \App\Support\UsageMath::display($line->billable) }}</td><td class="right" data-label="Estimated">{{ money($line->amount, $service->currency) }}@unless ($line->priced) <span class="muted small">(no price)</span>@endunless</td></tr>
        @endforeach
        <tr><td colspan="4"><b>Estimated usage charge (before tax)</b></td><td class="right"><b>{{ money($summary->total(), $service->currency) }}</b></td></tr>
        </tbody></table>
    @endif
    @if ($service->status->isLive())
    <h4 class="mt-24 mb-8">Record usage</h4>
    <form method="POST" action="{{ route('admin.services.usage.store', $service) }}" class="grid3 align-end">@csrf
        <label><span>Meter</span><select name="meter">@foreach ($meters as $m)<option value="{{ $m->key }}">{{ $m->name }} ({{ $m->unit }})</option>@endforeach</select></label>
        <label><span>Quantity</span><input type="number" inputmode="decimal" step="any" min="0" name="quantity" required></label>
        <label><span>When (optional)</span><input type="datetime-local" name="recorded_at"></label>
        <label><span>Idempotency key (optional)</span><input name="idempotency_key" maxlength="191"></label>
        <div><button class="btn primary sm" type="submit">Record usage</button></div>
    </form>
    @endif
    @php($recent = $usageSvc->recentRecords($service))
    @if ($recent->isNotEmpty())
        <h4 class="mt-24 mb-8">Recent records</h4>
        <table class="t stack-sm"><thead><tr><th>When</th><th>Meter</th><th class="right">Quantity</th><th>Source</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody>
        @foreach ($recent as $r)
            <tr><td data-label="When">{{ $r->recorded_at->format('j M Y H:i') }}</td><td data-label="Meter">{{ $r->meter?->name }}</td><td class="right" data-label="Quantity">{{ \App\Support\UsageMath::display($r->quantity) }}</td><td data-label="Source">{{ $r->source }}</td><td data-label="Status"><span class="pill {{ $r->isInvoiced() ? 'good' : 'warn' }}">{{ $r->isInvoiced() ? 'Invoiced' : 'Unbilled' }}</span></td>
            <td>@unless ($r->isInvoiced())<form method="POST" action="{{ route('admin.services.usage.destroy', [$service, $r->id]) }}" data-confirm="Delete this usage record?" data-confirm-label="Delete" data-danger>@csrf @method('DELETE')<button class="btn ghost sm" type="submit">Delete</button></form>@endunless</td></tr>
        @endforeach
        </tbody></table>
    @endif
</x-card>
@endif
