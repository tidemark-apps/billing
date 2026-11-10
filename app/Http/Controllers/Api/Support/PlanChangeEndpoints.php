<?php

namespace App\Http\Controllers\Api\Support;

use App\Enums\BillingCycle;
use App\Http\Resources\PlanChangeResource;
use App\Models\Product;
use App\Models\Service;
use App\Services\PlanChangeService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The plan-change endpoints shared by the admin and the client API. Staff may force the timing and choose any active
 * product; customers can only pick what options() offers them and only when customerCanChange() allows it.
 */
trait PlanChangeEndpoints
{
    private function planOptionsResponse(Service $service, bool $staff)
    {
        $plans = app(PlanChangeService::class);
        $can = $staff ? $plans->changeable($service) : $plans->customerCanChange($service);
        $options = $can ? $plans->options($service, $staff)->map(fn ($o) => [
            'product_id' => $o['product']->id,
            'product_name' => $o['product']->name,
            'billing_cycle' => $o['cycle']->value,
            'price' => ['amount' => $o['amount'], 'currency' => $service->currency, 'formatted' => Money::format($o['amount'], $service->currency)],
        ])->values() : [];

        return response()->json(['data' => ['can_change_plan' => $can, 'options' => $options]]);
    }

    private function planPreviewResponse(Request $request, Service $service, bool $staff)
    {
        [$product, $cycle, $timing] = $this->planInput($request, $service, $staff);
        $plan = app(PlanChangeService::class)->preview($service, $product, $cycle, $timing, $staff);
        $money = fn (int $amount) => ['amount' => $amount, 'currency' => $plan['currency'], 'formatted' => Money::format($amount, $plan['currency'])];
        $ref = fn (Product $p) => ['id' => $p->id, 'name' => $p->name];

        return response()->json(['data' => [
            'direction' => $plan['direction'],
            'timing' => $plan['timing'],
            'trial' => $plan['trial'],
            'from_product' => $ref($plan['from_product']),
            'to_product' => $ref($plan['to_product']),
            'from_cycle' => $plan['from_cycle']->value,
            'to_cycle' => $plan['to_cycle']->value,
            'from_amount' => $money($plan['from_amount']),
            'to_amount' => $money($plan['to_amount']),
            'credit' => $money($plan['credit']),
            'charge' => $money($plan['charge']),
            'amount_due' => $money($plan['amount_due']),
            'forfeited' => $money($plan['forfeited']),
            'effective_date' => $plan['effective_date']->toDateString(),
            'next_due_date' => $plan['next_due_date']->toDateString(),
            'period_start' => $plan['period_start']->toDateString(),
            'period_end' => $plan['period_end']->toDateString(),
        ]]);
    }

    private function planChangeResponse(Request $request, Service $service, bool $staff)
    {
        [$product, $cycle, $timing] = $this->planInput($request, $service, $staff);
        $change = app(PlanChangeService::class)->change($service, $product, $cycle, $timing, $staff ? 'api' : 'customer');
        $this->audit('service.plan_change', $service, 'Plan change to '.$product->name.' via the API', ['plan_change_id' => $change->id]);

        return (new PlanChangeResource($change->load('fromProduct', 'toProduct', 'invoice')))->response()->setStatusCode(200);
    }

    private function planCancelResponse(Service $service, bool $staff)
    {
        if (! $staff && ! app(PlanChangeService::class)->customerCanChange($service)) {
            throw new \DomainException('Plan changes are not available for this service.');
        }
        if (! app(PlanChangeService::class)->cancelScheduled($service)) {
            throw new \DomainException('There is no scheduled plan change to cancel.');
        }

        return response()->noContent();
    }

    /** @return array{0: Product, 1: BillingCycle, 2: ?string} */
    private function planInput(Request $request, Service $service, bool $staff): array
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'timing' => ['nullable', Rule::in(['immediate', 'renewal'])],
        ]);
        $plans = app(PlanChangeService::class);
        $cycle = BillingCycle::from($data['billing_cycle']);
        if (! $staff) {
            if (! $plans->customerCanChange($service)) {
                throw new \DomainException('Plan changes are not available for this service.');
            }
            // Customers can only move to a plan they are offered (same group, visible, priced in their currency).
            $offered = $plans->options($service, false)->contains(fn ($o) => $o['product']->id === (int) $data['product_id'] && $o['cycle'] === $cycle);
            if (! $offered) {
                throw new \DomainException('That plan is not available for this service.');
            }
        }
        $product = Product::with('prices')->find($data['product_id']);
        abort_unless($product, 404, 'Product not found.');

        return [$product, $cycle, $data['timing'] ?? null];
    }
}
