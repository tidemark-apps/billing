<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\TaxRateResource;
use App\Models\TaxRate;
use Illuminate\Http\Request;

class TaxRateController extends ApiController
{
    public function index(Request $request)
    {
        return $this->paginate(TaxRate::orderBy('id'), TaxRateResource::class, $request);
    }

    public function store(Request $request)
    {
        $rate = TaxRate::create($this->validated($request));
        $this->audit('tax_rate.created', $rate, 'Added tax rate '.$rate->name);

        return (new TaxRateResource($rate->refresh()))->response()->setStatusCode(201);
    }

    public function show(TaxRate $taxRate): TaxRateResource
    {
        return new TaxRateResource($taxRate);
    }

    public function update(Request $request, TaxRate $taxRate): TaxRateResource
    {
        $taxRate->update($this->validated($request, true));
        $this->audit('tax_rate.updated', $taxRate, 'Updated tax rate '.$taxRate->name);

        return new TaxRateResource($taxRate->refresh());
    }

    public function destroy(Request $request, TaxRate $taxRate)
    {
        $this->requireAdmin($request, 'Only administrators can delete tax rates.');
        $taxRate->delete();
        $this->audit('tax_rate.deleted', null, 'Deleted tax rate '.$taxRate->name);

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $s = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$s, 'string', 'max:255'],
            'rate' => [$s, 'numeric', 'min:0', 'max:100'],
            'country' => ['nullable', 'string', 'size:2'],
            'region' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        if (! empty($data['country'])) {
            $data['country'] = strtoupper($data['country']);
        }

        return $data;
    }
}
