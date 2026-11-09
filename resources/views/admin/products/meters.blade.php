@php
    $currencies = collect($product->exists ? $product->prices->pluck('currency') : [])->push($defaultCurrency)->unique()->values()->all();
    $meterRows = \App\Support\MeterInput::formRows($product, $currencies);
@endphp
<x-card title="Metered usage">
    <input type="hidden" name="meters_present" value="1">
    <div data-repeater data-next="{{ count($meterRows) }}" data-min="0">
        <div data-rows>
        @foreach ($meterRows as $i => $m)
            @include('admin.products.meter-row', ['i' => $i, 'm' => $m, 'currencies' => $currencies])
        @endforeach
        </div>
        <template>@include('admin.products.meter-row', ['i' => '__INDEX__', 'm' => ['aggregation' => 'sum', 'pricing_model' => 'per_unit'], 'currencies' => $currencies])</template>
        <button type="button" class="btn sm" data-add><x-icon name="plus" :size="14" /> Add meter</button>
    </div>
    <p class="help" style="margin-top:8px">Optional. Usage is billed in arrears on the renewal invoice, one line per meter, after the included allowance. Prices are in major units and may have many decimals (0.002 per call). Graduated tiers: one per line as <code>up to | price | flat fee</code> using cumulative quantities; leave “up to” blank on the last line. Package: every started package of the chosen size costs the price. A product with a zero recurring price and meters still invoices its usage.</p>
</x-card>
