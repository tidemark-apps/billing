<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Plugins\GatewayRegistry;
use Illuminate\Http\Request;

class CatalogController extends ApiController
{
    /** Public: visible, active products with their prices. */
    public function products(Request $request)
    {
        $query = Product::with('prices', 'group', 'meters.prices')->where('is_active', true)->where('is_visible', true)->orderBy('sort')->orderBy('name');
        if ($request->filled('group_id')) {
            $query->where('product_group_id', $request->query('group_id'));
        }
        if ($request->filled('q')) {
            $query->whereContains('name', (string) $request->query('q'));
        }

        return $this->paginate($query, ProductResource::class, $request);
    }

    public function product(string $product): ProductResource
    {
        $model = Product::with('prices', 'group', 'meters.prices')->where('is_active', true)->where('is_visible', true)
            ->where(fn ($q) => is_numeric($product) ? $q->where('id', $product) : $q->where('slug', $product))->firstOrFail();

        return new ProductResource($model);
    }

    /** Payment methods that can currently be used (optionally for one currency). */
    public function gateways(Request $request, GatewayRegistry $gateways)
    {
        $currency = $request->query('currency', $request->user()->client->currency);

        return response()->json(['data' => collect($gateways->available($currency))->map(fn ($g, $key) => [
            'key' => $key, 'title' => $gateways->title($key), 'description' => $g->description(),
        ])->values()]);
    }
}
