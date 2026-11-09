<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Support\Settings;

/** The product catalogue: public at /store, and inside the client area at /portal/store. */
class StoreController extends Controller
{
    public function index()
    {
        return view('store.index', $this->catalogue());
    }

    public function portal()
    {
        return view('store.portal', $this->catalogue());
    }

    public function show(Product $product)
    {
        abort_unless($product->is_active && $product->is_visible, 404);
        $product->load('prices');
        $currency = $this->currency();
        $prices = $product->pricesIn($currency);
        if ($prices->isEmpty()) {
            $currency = $this->defaultCurrency();
            $prices = $product->pricesIn($currency);
        }
        abort_if($prices->isEmpty(), 404);

        return view('store.show', ['product' => $product, 'prices' => $prices, 'currency' => $currency]);
    }

    /** @return array<string, mixed> */
    private function catalogue(): array
    {
        $currency = $this->currency();
        $products = Product::with('prices')->where('is_active', true)->where('is_visible', true)->orderBy('sort')->orderBy('name')->get()
            ->map(function (Product $p) use ($currency) {
                $prices = $p->pricesIn($currency);
                $cur = $currency;
                if ($prices->isEmpty()) {
                    $cur = $this->defaultCurrency();
                    $prices = $p->pricesIn($cur);
                }
                $p->setAttribute('from_price', $prices->sortBy('amount')->first());
                $p->setAttribute('from_currency', $cur);

                return $p;
            })->filter(fn (Product $p) => $p->from_price !== null);

        $groups = ProductGroup::where('is_visible', true)->orderBy('sort')->orderBy('name')->get()
            ->map(fn (ProductGroup $g) => ['group' => $g, 'products' => $products->where('product_group_id', $g->id)->values()])
            ->filter(fn ($g) => $g['products']->isNotEmpty())->values();
        $visibleGroupIds = $groups->pluck('group.id');

        return ['groups' => $groups, 'ungrouped' => $products->reject(fn ($p) => $visibleGroupIds->contains($p->product_group_id))->values()];
    }

    private function defaultCurrency(): string
    {
        return (string) Settings::get('billing.currency', config('billing.currency'));
    }

    private function currency(): string
    {
        return auth()->user()?->client?->currency ?: $this->defaultCurrency();
    }
}
