{{--
  The line-item editor shared by invoices and quotes. Needs $rows, $currency, $taxRate, $taxName, $catalog, $digitsMap;
  $quote = true shows the service label field. Live totals come from the data-repeater script in assets/js/app.js.
--}}
@php($quote = $quote ?? false)
<div>
    <div class="row wrap spread" style="margin-bottom:12px">
        <div><b>Line items</b><div class="help">Amounts in {{ $currency }}. Total updates as you type.</div></div>
        @if ($catalog)
            <select id="catalog-picker" style="width:auto;min-width:240px" aria-label="Add from catalogue">
                <option value="">Add from catalogue…</option>
                @foreach ($catalog as $c)
                    <option value="{{ $loop->index }}" data-currency="{{ $c['currency'] }}" data-product="{{ $c['product_id'] }}" data-cycle="{{ $c['cycle'] }}" data-amount="{{ $c['amount'] }}" data-description="{{ $c['description'] }}">{{ $c['label'] }}</option>
                @endforeach
            </select>
        @endif
    </div>
    <div data-repeater data-next="{{ count($rows) }}" data-min="1" data-digits="{{ $digitsMap[$currency] ?? 2 }}" data-tax-rate="{{ $taxRate }}">
        <div class="line-row head" aria-hidden="true"><span>Description</span><span>Qty</span><span>Unit price (<span data-cur-label>{{ $currency }}</span>)</span><span>Taxable</span><span class="right">Amount</span><span></span></div>
        <div data-rows>
            @foreach ($rows as $i => $row)
                @include('admin.partials.line-row', ['i' => $i, 'row' => $row, 'quote' => $quote])
            @endforeach
        </div>
        <template>@include('admin.partials.line-row', ['i' => '__INDEX__', 'row' => ['quantity' => 1, 'taxable' => true], 'quote' => $quote])</template>
        @error('items')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        <button type="button" class="btn sm" data-add><x-icon name="plus" :size="14" /> Add line</button>
    </div>
    <div class="sum-box">
        <div><span>Subtotal</span><span data-sum="subtotal">0.00</span></div>
        <div><span data-tax-label>{{ $taxName ?: 'Tax' }}</span><span data-sum="tax">0.00</span></div>
        <div class="total"><span>Total (<span data-cur-label>{{ $currency }}</span>)</span><span data-sum="total">0.00</span></div>
    </div>
</div>
@push('scripts')
<script>
(function () {
  var rep = document.querySelector('[data-repeater]'); if (!rep) return;
  var form = rep.closest('form'), digits = @json($digitsMap), taxUrl = @json(route('admin.invoices.tax-rate', '__ID__'));
  var cur = form.querySelector('[name=currency]'), client = form.querySelector('[name=client_id]'), picker = document.getElementById('catalog-picker');
  function refresh() { rep.dispatchEvent(new Event('change', { bubbles: true })); }
  function syncCurrency() {
    rep.dataset.digits = digits[cur.value] !== undefined ? digits[cur.value] : 2;
    document.querySelectorAll('[data-cur-label]').forEach(function (e) { e.textContent = cur.value; });
    if (picker) Array.prototype.forEach.call(picker.options, function (o) { if (o.value !== '') o.hidden = o.dataset.currency !== cur.value; });
    refresh();
  }
  cur.addEventListener('change', syncCurrency); syncCurrency();
  client.addEventListener('change', function () {
    if (!client.value) return;
    fetch(taxUrl.replace('__ID__', client.value), { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      rep.dataset.taxRate = j.rate; var l = form.querySelector('[data-tax-label]'); if (l) l.textContent = j.name || 'Tax';
      if (@json($isNew ?? false) && j.currency && cur.querySelector('option[value="' + j.currency + '"]')) cur.value = j.currency;
      syncCurrency();
    });
  });
  if (picker) picker.addEventListener('change', function () {
    var o = picker.options[picker.selectedIndex]; if (!o || o.value === '') return;
    var rows = rep.querySelectorAll('[data-row]'), last = rows[rows.length - 1];
    if (!(rows.length === 1 && !last.querySelector('[name$="[description]"]').value && !last.querySelector('[data-price]').value)) {
      rep.querySelector('[data-add]').click(); rows = rep.querySelectorAll('[data-row]'); last = rows[rows.length - 1];
    }
    last.querySelector('[name$="[description]"]').value = o.dataset.description;
    last.querySelector('[data-price]').value = o.dataset.amount;
    last.querySelector('[data-product]').value = o.dataset.product;
    last.querySelector('[data-cycle]').value = o.dataset.cycle;
    var hint = last.querySelector('[data-linked]'); if (hint) { hint.hidden = false; }
    picker.value = ''; refresh();
  });
})();
</script>
@endpush
