<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Support\Checkout;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\Request;

class InvoiceController extends ApiController
{
    public function index(Request $request)
    {
        $query = $this->client($request)->invoices()->where('status', '!=', 'draft')->latest('id');
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return $this->paginate($query, InvoiceResource::class, $request);
    }

    public function show(Request $request, string $invoice): InvoiceResource
    {
        return new InvoiceResource($this->find($request, $invoice)->load('items', 'payments'));
    }

    /** Start paying: returns the URL to send the customer to. */
    public function pay(Request $request, string $invoice, Checkout $checkout)
    {
        $data = $request->validate(['gateway' => ['required', 'string']]);
        $model = $this->find($request, $invoice)->load('client');

        return response()->json(['data' => ['checkout_url' => $checkout->url($model, $data['gateway'])]]);
    }

    private function find(Request $request, string $uuid): Invoice
    {
        return $this->client($request)->invoices()->where('status', '!=', 'draft')->where('uuid', $uuid)->firstOrFail();
    }
}
