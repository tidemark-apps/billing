<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\ProductResource;
use App\Models\ApiToken;
use App\Models\Product;
use App\Plugins\GatewayRegistry;
use Illuminate\Http\Request;

class CatalogController extends ApiController
{
    /** Public: visible, active products with their prices. */
    public function products(Request $request)
    {
        $this->identify($request);
        $query = Product::with('prices', 'group', 'meters.prices')->where('is_active', true)->where('is_visible', true)->orderBy('sort')->orderBy('name');
        if ($request->filled('group_id')) {
            $query->where('product_group_id', $request->query('group_id'));
        }
        if ($request->filled('q')) {
            $query->whereContains('name', (string) $request->query('q'));
        }

        return $this->paginate($query, ProductResource::class, $request);
    }

    public function product(Request $request, string $product): ProductResource
    {
        $this->identify($request);
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

    /** The catalogue is public, but a customer token (if one is sent) lets products say whether a trial is open to that client. */
    private function identify(Request $request): void
    {
        $token = $request->bearerToken() ? ApiToken::findPlain($request->bearerToken()) : null;
        $user = $token && ! $token->isExpired() ? $token->user : null;
        if ($user && $user->is_active && $user->isCustomer() && $user->client_id) {
            $request->attributes->set('catalog_client', $user->client);
        }
    }
}
