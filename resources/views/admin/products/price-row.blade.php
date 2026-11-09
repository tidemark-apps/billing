<div class="line-row" data-row style="grid-template-columns:1fr 100px 1fr 1fr 36px">
    <select name="prices[{{ $i }}][cycle]" aria-label="Billing cycle">@foreach (\App\Enums\BillingCycle::options() as $k => $v)<option value="{{ $k }}" @selected(($p['cycle'] ?? '') === $k)>{{ $v }}</option>@endforeach</select>
    <select name="prices[{{ $i }}][currency]" aria-label="Currency">@foreach (\App\Support\Money::currencies() as $k => $v)<option value="{{ $k }}" @selected(($p['currency'] ?? '') === $k)>{{ $k }}</option>@endforeach</select>
    <input type="number" step="0.01" min="0" name="prices[{{ $i }}][amount]" value="{{ $p['amount'] ?? '' }}" placeholder="0.00" aria-label="Price" required>
    <input type="number" step="0.01" min="0" name="prices[{{ $i }}][setup_fee]" value="{{ $p['setup_fee'] ?? '' }}" placeholder="0.00" aria-label="Setup fee">
    <button type="button" class="btn ghost icon sm" data-remove aria-label="Remove price"><x-icon name="x" :size="14" /></button>
</div>
