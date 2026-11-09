@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Tax rates')
@section('content')
<x-page-head title="Tax rates" sub="The most specific active rate for a client's country and region is added to their invoices and quotes. A rate with no country applies everywhere else." :back="Route::has('admin.settings.index') ? route('admin.settings.index') : null" back-label="Settings" />
<x-card :flush="true">
    @if ($rates->isEmpty())<x-empty icon="percent" title="No tax rates" text="Add a rate below to start charging tax. Until then invoices are issued without it." />@else
    <div class="tscroll"><table class="t"><thead><tr><th>Name</th><th>Rate %</th><th>Country</th><th>Region</th><th>Active</th><th></th></tr></thead><tbody>
        @foreach ($rates as $r)
            <tr>
                <td><input form="rate-{{ $r->id }}" name="name" value="{{ $r->name }}" required aria-label="Name"></td>
                <td style="width:110px"><input form="rate-{{ $r->id }}" type="number" step="any" min="0" max="100" name="rate" value="{{ rtrim(rtrim(number_format($r->rate, 3, '.', ''), '0'), '.') }}" required aria-label="Rate"></td>
                <td style="width:180px"><select form="rate-{{ $r->id }}" name="country" aria-label="Country"><option value="">Everywhere</option>@foreach ($countries as $code => $n)<option value="{{ $code }}" @selected($r->country === $code)>{{ $n }}</option>@endforeach</select></td>
                <td><input form="rate-{{ $r->id }}" name="region" value="{{ $r->region }}" placeholder="Any" aria-label="Region"></td>
                <td><input form="rate-{{ $r->id }}" type="hidden" name="is_active" value="0"><input form="rate-{{ $r->id }}" type="checkbox" name="is_active" value="1" @checked($r->is_active) aria-label="Active"></td>
                <td class="right" style="white-space:nowrap"><button class="btn sm" form="rate-{{ $r->id }}" type="submit">Save</button>
                    <form method="POST" action="{{ route('admin.tax-rates.destroy', $r) }}" style="display:inline" data-confirm="Delete the {{ $r->name }} rate? Existing invoices keep it." data-danger data-confirm-label="Delete">@csrf @method('DELETE')<button class="btn ghost danger sm icon" type="submit" aria-label="Delete"><x-icon name="trash-2" :size="14" /></button></form></td>
            </tr>
        @endforeach
    </tbody></table></div>
    @foreach ($rates as $r)<form id="rate-{{ $r->id }}" method="POST" action="{{ route('admin.tax-rates.update', $r) }}">@csrf @method('PUT')</form>@endforeach
    @endif
</x-card>
<x-setting title="Add a tax rate" :action="route('admin.tax-rates.store')">
    <div class="grid2">
        <x-field name="name" label="Name" required placeholder="VAT" />
        <x-field name="rate" label="Rate (%)" type="number" step="any" min="0" max="100" required placeholder="20" />
        <x-field name="country" label="Country" type="select" :options="['' => 'Everywhere'] + $countries" />
        <x-field name="region" label="State / region" help="Optional. Matches the client's state exactly." />
    </div>
    <x-field name="is_active" label="Active" type="checkbox" :value="true" />
    <x-slot:footer><button class="btn primary sm" type="submit">Add rate</button></x-slot:footer>
</x-setting>
@endsection
