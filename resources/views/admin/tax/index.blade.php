@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Tax rates')
@section('content')
@php
    $active = $rates->where('is_active', true)->count();
@endphp
<x-page-head title="Tax rates" sub="The most specific active rate for a client's country and region is added to their invoices and quotes. A rate with no country applies everywhere else." :back="Route::has('admin.settings.index') ? route('admin.settings.index') : null" back-label="Settings">
    <a class="btn primary" href="#add-rate" data-focus="#add-name"><x-icon name="plus" :size="15" /> Add tax rate</a>
</x-page-head>
<x-card :flush="true">
    @if ($rates->isEmpty())<x-empty icon="percent" title="No tax rates yet" text="Until you add one, invoices are issued without tax."><a class="btn primary" href="#add-rate" data-focus="#add-name">Add tax rate</a></x-empty>@else
    <div class="tax-sum muted small">{{ $rates->count() }} {{ \Illuminate\Support\Str::plural('rate', $rates->count()) }}, {{ $active }} active. Changes to a row apply when you press Save on that row.</div>
    <div class="tscroll"><table class="t stack-sm tax-t"><thead><tr><th>Name</th><th class="right">Rate (%)</th><th>Country</th><th>Region (optional)</th><th>Active</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody>
        @foreach ($rates as $r)
            @php
                $bag = $errors->getBag('rate-'.$r->id);
                $v = fn ($f, $d) => $bag->any() ? old($f, $d) : $d;
            @endphp
            <tr class="{{ $r->is_active ? '' : 'is-off' }}">
                <td data-label="Name"><input form="rate-{{ $r->id }}" name="name" value="{{ $v('name', $r->name) }}" required aria-label="Name" @if ($bag->has('name')) aria-invalid="true" @endif>@if ($bag->has('name'))<div class="field-error">{{ $bag->first('name') }}</div>@endif</td>
                <td data-label="Rate (%)"><input class="num-in" form="rate-{{ $r->id }}" type="number" step="any" min="0" max="100" name="rate" value="{{ $v('rate', rtrim(rtrim(number_format($r->rate, 3, '.', ''), '0'), '.')) }}" required aria-label="Rate, percent" inputmode="decimal" @if ($bag->has('rate')) aria-invalid="true" @endif>@if ($bag->has('rate'))<div class="field-error">{{ $bag->first('rate') }}</div>@endif</td>
                <td data-label="Country"><select form="rate-{{ $r->id }}" name="country" aria-label="Country"><option value="">Everywhere</option>@foreach ($countries as $code => $n)<option value="{{ $code }}" @selected($v('country', $r->country) === $code)>{{ $n }}</option>@endforeach</select>@if ($bag->has('country'))<div class="field-error">{{ $bag->first('country') }}</div>@endif</td>
                <td data-label="Region"><input form="rate-{{ $r->id }}" name="region" value="{{ $v('region', $r->region) }}" placeholder="Any region" aria-label="Region"></td>
                <td data-label="Active"><label class="check"><input form="rate-{{ $r->id }}" type="hidden" name="is_active" value="0"><input form="rate-{{ $r->id }}" type="checkbox" name="is_active" value="1" @checked($r->is_active)> <span>{{ $r->is_active ? 'Active' : 'Off' }}</span></label></td>
                <td class="right"><div class="row nowrap"><button class="btn" form="rate-{{ $r->id }}" type="submit">Save</button>
                    <form method="POST" action="{{ route('admin.tax-rates.destroy', $r) }}" data-confirm="Delete the {{ $r->name }} ({{ rtrim(rtrim(number_format($r->rate, 3, '.', ''), '0'), '.') }}%) rate? Existing invoices keep it." data-danger data-confirm-label="Delete rate">@csrf @method('DELETE')<button class="btn ghost danger icon" type="submit" aria-label="Delete {{ $r->name }}" title="Delete {{ $r->name }}"><x-icon name="trash-2" :size="14" /></button></form></div></td>
            </tr>
        @endforeach
    </tbody></table></div>
    @foreach ($rates as $r)<form id="rate-{{ $r->id }}" method="POST" action="{{ route('admin.tax-rates.update', $r) }}">@csrf @method('PUT')</form>@endforeach
    @endif
</x-card>
<x-setting id="add-rate" title="Add a tax rate" description="New rates are active straight away unless you switch them off." :action="route('admin.tax-rates.store')">
    <div class="stack">
        <div class="grid2">
            <label for="add-name"><span>Name</span><input id="add-name" name="name" value="{{ old('name') }}" placeholder="VAT" required @if ($errors->has('name')) aria-invalid="true" @endif>@error('name')<span class="field-error">{{ $message }}</span>@enderror</label>
            <label for="add-rate-pct"><span>Rate (%)</span><input id="add-rate-pct" type="number" step="any" min="0" max="100" name="rate" value="{{ old('rate') }}" placeholder="20" required inputmode="decimal" @if ($errors->has('rate')) aria-invalid="true" @endif>@error('rate')<span class="field-error">{{ $message }}</span>@enderror</label>
        </div>
        <div class="grid2">
            <label for="add-country"><span>Country</span><select id="add-country" name="country"><option value="">Everywhere</option>@foreach ($countries as $code => $n)<option value="{{ $code }}" @selected(old('country') === $code)>{{ $n }}</option>@endforeach</select>@error('country')<span class="field-error">{{ $message }}</span>@enderror</label>
            <label for="add-region"><span>State or region (optional)</span><input id="add-region" name="region" value="{{ old('region') }}"><span class="help">Matches the client's state exactly.</span></label>
        </div>
        <x-field name="is_active" label="Active" type="checkbox" :value="old('is_active', true)" id="add-active" />
    </div>
    <x-slot:footer><button class="btn primary sm" type="submit">Add rate</button></x-slot:footer>
</x-setting>
@endsection
