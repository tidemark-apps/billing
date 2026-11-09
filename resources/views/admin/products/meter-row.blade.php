@php($n = "meters[{$i}]")
<div data-row style="border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:12px">
    <div class="grid3">
        <label><span>Key</span><input name="{{ $n }}[key]" value="{{ $m['key'] ?? '' }}" placeholder="api_calls" pattern="[a-z0-9]+([_\-][a-z0-9]+)*" required><span class="help">Used when recording usage. Lowercase, no spaces.</span>@error("meters.$i.key")<span class="field-error">{{ $message }}</span>@enderror</label>
        <label><span>Name</span><input name="{{ $n }}[name]" value="{{ $m['name'] ?? '' }}" placeholder="API calls" required></label>
        <label><span>Unit label</span><input name="{{ $n }}[unit]" value="{{ $m['unit'] ?? '' }}" placeholder="calls"></label>
        <label><span>Aggregation</span><select name="{{ $n }}[aggregation]">@foreach (\App\Models\UsageMeter::AGGREGATIONS as $k => $v)<option value="{{ $k }}" @selected(($m['aggregation'] ?? 'sum') === $k)>{{ $v }}</option>@endforeach</select></label>
        <label><span>Included per period</span><input type="number" step="any" min="0" name="{{ $n }}[included_quantity]" value="{{ $m['included_quantity'] ?? '' }}" placeholder="0"></label>
        <label><span>Pricing model</span><select name="{{ $n }}[pricing_model]">@foreach (\App\Models\UsageMeter::MODELS as $k => $v)<option value="{{ $k }}" @selected(($m['pricing_model'] ?? 'per_unit') === $k)>{{ $v }}</option>@endforeach</select></label>
        <label><span>Package size</span><input type="number" step="any" min="0" name="{{ $n }}[package_size]" value="{{ $m['package_size'] ?? '' }}" placeholder="Package model only">@error("meters.$i.package_size")<span class="field-error">{{ $message }}</span>@enderror</label>
    </div>
    @foreach ($currencies as $cur)
        @php($pr = $m['prices'][$cur] ?? [])
        <div class="grid2" style="margin-top:12px">
            <label><span>Price per unit / package ({{ $cur }})</span><input type="number" step="any" min="0" name="{{ $n }}[prices][{{ $cur }}][unit_price]" value="{{ $pr['unit_price'] ?? '' }}" placeholder="0.002"></label>
            <label><span>Tiers ({{ $cur }}, graduated model only)</span><textarea rows="3" name="{{ $n }}[prices][{{ $cur }}][tiers]" placeholder="1000 | 0.002&#10;10000 | 0.0015&#10;| 0.001">{{ $pr['tiers'] ?? '' }}</textarea>@error("meters.$i.prices.$cur.tiers")<span class="field-error">{{ $message }}</span>@enderror</label>
        </div>
    @endforeach
    <div style="margin-top:10px"><button type="button" class="btn ghost sm" data-remove><x-icon name="x" :size="14" /> Remove meter</button></div>
</div>
