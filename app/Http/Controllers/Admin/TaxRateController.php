<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TaxRate;
use App\Support\Activity;
use App\Support\Countries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaxRateController extends Controller
{
    public function index()
    {
        return view('admin.tax.index', ['rates' => TaxRate::orderBy('country')->orderBy('name')->get(), 'countries' => Countries::all()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $rate = TaxRate::create($this->validated($request));
        Activity::log('tax_rate.created', $rate, 'Added tax rate '.$rate->name);

        return back()->with('status', 'Tax rate added.');
    }

    public function update(Request $request, TaxRate $tax_rate): RedirectResponse
    {
        $tax_rate->update($this->validated($request));
        Activity::log('tax_rate.updated', $tax_rate, 'Updated tax rate '.$tax_rate->name);

        return back()->with('status', 'Tax rate saved.');
    }

    public function destroy(TaxRate $tax_rate): RedirectResponse
    {
        $tax_rate->delete();
        Activity::log('tax_rate.deleted', null, 'Deleted tax rate '.$tax_rate->name);

        return back()->with('status', 'Tax rate deleted. Existing invoices keep the rate they were issued with.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'country' => ['nullable', 'string', 'size:2'],
            'region' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['country'] = isset($data['country']) ? strtoupper($data['country']) : null;
        $data['is_active'] = (bool) ($data['is_active'] ?? false);

        return $data;
    }
}
