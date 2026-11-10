<?php

namespace App\Http\Controllers\Api\Client;

use App\Enums\BillingCycle;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\ServiceResource;
use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends ApiController
{
    public function store(Request $request, OrderService $orders)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'label' => ['nullable', 'string', 'max:255'],
        ]);
        $product = Product::where('is_visible', true)->where('is_active', true)->find($data['product_id']);
        abort_unless($product, 404, 'Product not found.');

        $order = $orders->place($this->client($request), $product, BillingCycle::from($data['billing_cycle']), ['label' => $data['label'] ?? null]);
        $order['service']->load('product');
        $order['invoice']?->load('items');

        return response()->json(['data' => [
            'service' => (new ServiceResource($order['service']))->resolve($request),
            'invoice' => $order['invoice'] ? (new InvoiceResource($order['invoice']))->resolve($request) : null,
            'pay_url' => $order['invoice'] ? route('invoice.public', $order['invoice']->uuid) : null,
        ]], 201);
    }
}
