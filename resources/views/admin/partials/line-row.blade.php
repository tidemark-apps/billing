{{-- One editable line item. $i is the row index (or __INDEX__ in the template), $row the values, $quote adds service fields. Labels are visible on narrow screens; the column header covers desktop. --}}
@php($err = fn ($f) => $errors->first('items.'.$i.'.'.$f))
<div class="line-row" data-row>
    <div class="lf lf-desc">
        <label for="li-{{ $i }}-d"><span class="lbl">Description</span></label>
        <input id="li-{{ $i }}-d" name="items[{{ $i }}][description]" value="{{ $row['description'] ?? '' }}" placeholder="What are you billing for?" maxlength="255" aria-required="true" data-msg="Describe this line." @if ($err('description')) aria-invalid="true" @endif>
        @if ($err('description'))<span class="field-error">{{ $err('description') }}</span>@endif
        @if (! empty($quote))
            <label for="li-{{ $i }}-l"><span class="lbl show">Service label (optional)</span></label>
            <input id="li-{{ $i }}-l" name="items[{{ $i }}][label]" value="{{ $row['label'] ?? '' }}" placeholder="Service label (optional), e.g. a domain" maxlength="255">
        @endif
        <input type="hidden" name="items[{{ $i }}][product_id]" value="{{ $row['product_id'] ?? '' }}" data-product>
        <input type="hidden" name="items[{{ $i }}][billing_cycle]" value="{{ $row['billing_cycle'] ?? '' }}" data-cycle>
        <span class="help" data-linked @if (empty($row['product_id'])) hidden @endif>{{ ! empty($quote) && ! empty($row['billing_cycle']) ? 'Orders the product as a '.str_replace('_', ' ', $row['billing_cycle']).' service when accepted.' : 'Linked to a catalogue product.' }}</span>
    </div>
    <div class="lf">
        <label for="li-{{ $i }}-q"><span class="lbl">Qty</span></label>
        <input id="li-{{ $i }}-q" type="number" step="any" min="0" name="items[{{ $i }}][quantity]" value="{{ $row['quantity'] ?? 1 }}" data-qty inputmode="decimal" @if ($err('quantity')) aria-invalid="true" @endif>
        @if ($err('quantity'))<span class="field-error">{{ $err('quantity') }}</span>@endif
    </div>
    <div class="lf">
        <label for="li-{{ $i }}-p"><span class="lbl">Unit price</span></label>
        <input id="li-{{ $i }}-p" type="number" step="any" min="0" name="items[{{ $i }}][unit_price]" value="{{ $row['unit_price'] ?? '' }}" data-price placeholder="0.00" inputmode="decimal" aria-required="true" data-msg="Enter a unit price (0 is allowed)." @if ($err('unit_price')) aria-invalid="true" @endif>
        @if ($err('unit_price'))<span class="field-error">{{ $err('unit_price') }}</span>@endif
    </div>
    <div class="lf">
        <label class="check" for="li-{{ $i }}-t"><input type="hidden" name="items[{{ $i }}][taxable]" value="0"><input id="li-{{ $i }}-t" type="checkbox" name="items[{{ $i }}][taxable]" value="1" data-taxable @checked(filter_var($row['taxable'] ?? true, FILTER_VALIDATE_BOOL))> <span class="lbl">Taxable</span></label>
    </div>
    <div class="lf lf-amount"><span class="lbl">Amount</span><div class="amount" data-line-total>0.00</div></div>
    <button type="button" class="btn ghost icon sm" data-remove aria-label="Remove line" title="Remove line"><x-icon name="x" /></button>
</div>
