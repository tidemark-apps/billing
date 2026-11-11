<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductPrice;
use App\Services\AddonService;
use App\Services\TaxService;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

    public function show(Request $request, Product $product, TaxService $tax, AddonService $addons)
    {
        abort_unless($product->is_active && $product->is_visible && ! $product->isAddon(), 404);
        $product->load('prices');
        $currency = $this->currency();
        $prices = $product->pricesIn($currency);
        if ($prices->isEmpty()) {
            $currency = $this->defaultCurrency();
            $prices = $product->pricesIn($currency);
        }
        if ($prices->isEmpty()) {
            return response()->view('store.unavailable', ['product' => $product], 404);
        }

        $client = auth()->user()?->client;
        $rate = $client && ! $product->tax_exempt ? $tax->rateFor($client) : null;
        $trialUsed = $client !== null && $product->offersTrial() && ! $product->trialAvailableTo($client);
        $trial = $product->offersTrial() && ! $trialUsed;
        $selected = $prices->first(fn ($p) => $p->cycle->value === $request->query('cycle')) ?? $prices->first();
        $savings = self::savings($prices);
        $due = $prices->mapWithKeys(fn ($p) => [$p->cycle->value => self::dueToday($p, $rate, $trial && $p->cycle->isRecurring())]);

        return view('store.show', [
            'product' => $product, 'prices' => $prices, 'currency' => $currency, 'selected' => $selected, 'label' => (string) $request->query('label', ''),
            'savings' => $savings, 'due' => $due, 'trial' => $trial, 'trialUsed' => $trialUsed, 'rate' => $rate, 'known' => $client !== null,
            'metered' => $product->meters()->exists(),
            'addons' => $addons->forPlan($product, $currency), 'pickedAddons' => array_map('strval', (array) $request->query('addons', [])),
        ]);
    }

    /**
     * Percent saved by paying a longer cycle up front versus repeating the shortest recurring cycle. Only cycles that are cheaper appear.
     *
     * @param  Collection<int, ProductPrice>  $prices
     * @return array<string, int> cycle value => whole percent
     */
    public static function savings($prices): array
    {
        $recurring = $prices->filter(fn ($p) => $p->cycle->isRecurring())->sortBy(fn ($p) => $p->cycle->months())->values();
        $base = $recurring->first();
        $out = [];
        if (! $base || $base->amount <= 0) {
            return $out;
        }
        foreach ($recurring->skip(1) as $p) {
            $repeat = $base->amount * $p->cycle->months() / $base->cycle->months();
            $pct = (int) round(($repeat - $p->amount) / $repeat * 100);
            if ($pct > 0) {
                $out[$p->cycle->value] = $pct;
            }
        }

        return $out;
    }

    /** @return array{setup: int, tax: int, total: int, today: int} amounts in minor units; "today" is zero for a free trial */
    public static function dueToday($price, $rate, bool $trial): array
    {
        $net = $price->amount + $price->setup_fee;
        $tax = $rate ? (int) round($net * $rate->rate / 100) : 0;

        return ['setup' => (int) $price->setup_fee, 'tax' => $tax, 'total' => $net + $tax, 'today' => $trial ? 0 : $net + $tax];
    }

    /** @return array<string, mixed> */
    private function catalogue(): array
    {
        $currency = $this->currency();
        $products = Product::plans()->with('prices')->withCount('meters')->where('is_active', true)->where('is_visible', true)->orderBy('sort')->orderBy('name')->get()
            ->map(function (Product $p) use ($currency) {
                $prices = $p->pricesIn($currency);
                $cur = $currency;
                if ($prices->isEmpty()) {
                    $cur = $this->defaultCurrency();
                    $prices = $p->pricesIn($cur);
                }
                $p->setAttribute('from_price', $prices->first());
                $p->setAttribute('savings', self::savings($prices));
                $p->setAttribute('best_saving', collect(self::savings($prices))->max());
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
