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
            return redirect()->route('portal.services.show', $result['service'])->with('status', 'Your trial has started.');
        }

        return redirect()->route('portal.invoices.show', $result['invoice'])->with('status', 'Order placed. Pay the invoice to activate your service.');
    }

    /** @return array{0: Product, 1: BillingCycle, 2: ?ProductPrice} */
    private function resolve(Request $request): array
    {
        $product = Product::with('prices')->where('slug', (string) $request->input('product'))->where('is_active', true)->where('is_visible', true)->firstOrFail();
        $cycle = BillingCycle::tryFrom((string) $request->input('cycle')) ?? abort(404);

        return [$product, $cycle, $product->priceFor($cycle, $this->client()->currency)];
    }
}
