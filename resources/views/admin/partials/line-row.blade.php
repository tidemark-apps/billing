{{-- One editable line item. $i is the row index (or __INDEX__ in the template), $row the values, $quote adds service fields. --}}
<div class="line-row" data-row>
    <div class="stack sm" style="gap:4px">
        <input name="items[{{ $i }}][description]" value="{{ $row['description'] ?? '' }}" placeholder="Description" maxlength="255" required aria-label="Description">
        @if (! empty($quote))
            <input name="items[{{ $i }}][label]" value="{{ $row['label'] ?? '' }}" placeholder="Service label, e.g. a domain (optional)" maxlength="255" aria-label="Service label">
        @endif
        <input type="hidden" name="items[{{ $i }}][product_id]" value="{{ $row['product_id'] ?? '' }}" data-product>
        <input type="hidden" name="items[{{ $i }}][billing_cycle]" value="{{ $row['billing_cycle'] ?? '' }}" data-cycle>
        <span class="help" data-linked @if (empty($row['product_id'])) hidden @endif>{{ ! empty($quote) && ! empty($row['billing_cycle']) ? 'Orders the product as a '.str_replace('_', ' ', $row['billing_cycle']).' service when accepted.' : 'Linked to a catalogue product.' }}</span>
    </div>
    <input type="number" step="any" min="0" name="items[{{ $i }}][quantity]" value="{{ $row['quantity'] ?? 1 }}" data-qty aria-label="Quantity">
    <input type="number" step="any" min="0" name="items[{{ $i }}][unit_price]" value="{{ $row['unit_price'] ?? '' }}" data-price placeholder="0.00" required aria-label="Unit price">
    <label class="check"><input type="hidden" name="items[{{ $i }}][taxable]" value="0"><input type="checkbox" name="items[{{ $i }}][taxable]" value="1" data-taxable @checked(filter_var($row['taxable'] ?? true, FILTER_VALIDATE_BOOL))> Tax</label>
    <div class="amount" data-line-total>0.00</div>
    <button type="button" class="btn ghost icon sm" data-remove aria-label="Remove line"><x-icon name="x" /></button>
</div>
