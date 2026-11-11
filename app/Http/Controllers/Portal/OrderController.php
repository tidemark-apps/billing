<?php

namespace App\Http\Controllers\Portal;

use App\Enums\BillingCycle;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Services\OrderService;
use App\Services\TaxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderController extends PortalController
{
    public function create(Request $request, TaxService $tax)
    {
        [$product, $cycle, $price] = $this->resolve($request);
        if (! $price) {
            return redirect()->route('portal.store')->with('error', 'That product is not available for the selected billing cycle.');
        }
        $client = $this->client();
        $rate = $product->tax_exempt ? null : $tax->rateFor($client);
        $net = $price->amount + $price->setup_fee;
        $taxAmount = $rate ? (int) round($net * $rate->rate / 100) : 0;

        return view('portal.order.create', [
            'product' => $product, 'cycle' => $cycle, 'price' => $price, 'rate' => $rate,
            'trialAvailable' => $price->cycle->isRecurring() && $product->trialAvailableTo($client),
            'label' => $request->query('label'), 'tax' => $taxAmount, 'total' => $net + $taxAmount,
            'savedMethod' => PaymentMethod::where('client_id', $client->id)->orderByDesc('is_default')->first(),
            'termsUrl' => config('billing.legal.terms'),
        ]);
    }

    public function store(Request $request, OrderService $orders): RedirectResponse
    {
        [$product, $cycle, $price] = $this->resolve($request);
        $data = $request->validate(['label' => [$product?->label_prompt ? 'required' : 'nullable', 'string', 'max:255']]);
        if (! $price) {
            return redirect()->route('portal.store')->with('error', 'That product is not available for the selected billing cycle.');
        }
        $client = $this->client();
        try {
            $result = $orders->place($client, $product, $cycle, ['label' => $data['label'] ?? null]);
        } catch (\DomainException $e) {
            if ($product->trial_requires_payment_method && $product->trialAvailableTo($client) && ! PaymentMethod::where('client_id', $client->id)->exists()) {
                return redirect()->route('portal.order.create', ['product' => $product->slug, 'cycle' => $cycle->value, 'label' => $data['label'] ?? null])
                    ->with('error', $e->getMessage())->with('needs_payment_method', true);
            }

            return redirect()->route('portal.store')->with('error', $e->getMessage());
        }
        if ($result['invoice'] === null) {
            return redirect()->route('portal.services.show', $result['service'])->with('order_placed', ['product' => $product->name, 'trial' => true, 'trial_ends' => $result['service']->trial_ends_at?->format('j M Y')]);
        }

        return redirect()->route('portal.invoices.show', $result['invoice'])->with('order_placed', ['product' => $product->name, 'trial' => false, 'service' => $result['service']->id]);
    }

    /** @return array{0: Product, 1: BillingCycle, 2: ?ProductPrice} */
    private function resolve(Request $request): array
    {
        $product = Product::plans()->with('prices')->where('slug', (string) $request->input('product'))->where('is_active', true)->where('is_visible', true)->firstOrFail();
        $cycle = BillingCycle::tryFrom((string) $request->input('cycle')) ?? abort(404);

        return [$product, $cycle, $product->priceFor($cycle, $this->client()->currency)];
    }
}
