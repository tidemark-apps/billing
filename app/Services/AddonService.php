<?php

namespace App\Services;

use App\Enums\ServiceStatus;
use App\Events\ServiceCreated;
use App\Jobs\ProvisionServiceJob;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Service;
use App\Support\Activity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Add-ons: products that attach to an existing service (a "plan") and are billed with it.
 *
 * An add-on is its own service, linked to its parent by parent_service_id. It always bills on the parent's cycle and renewal date,
 * so the renewal run puts both on one invoice. When it is added mid-period the customer pays only for the days left (by the day,
 * like an upgrade); while the parent is on a free trial the add-on is free until the trial ends. Suspending, cancelling or
 * terminating the parent does the same to its add-ons (see ProvisioningService).
 */
class AddonService
{
    public function __construct(private readonly InvoiceService $invoices) {}

    /** Whether add-ons can be attached to this service right now. */
    public function canAttach(Service $parent): bool
    {
        return ! $parent->isAddon()
            && $parent->status === ServiceStatus::Active
            && ! $parent->cancel_at_period_end
            && $parent->billing_cycle->isRecurring()
            && $parent->next_due_date !== null
            && $parent->product !== null;
    }

    /**
     * Add-ons this service could take: active, visible, meant for its plan, priced for its cycle and currency, and not already attached.
     *
     * @return Collection<int, array{product: Product, amount: int}>
     */
    public function available(Service $parent, bool $staff = false): Collection
    {
        $parent->loadMissing('product');
        if (! $this->canAttach($parent)) {
            return collect();
        }
        $taken = $this->attachedProductIds($parent);

        return Product::with('prices')->where('kind', 'addon')->where('is_active', true)
            ->when(! $staff, fn ($q) => $q->where('is_visible', true))
            ->orderBy('sort')->orderBy('name')->get()
            ->filter(fn (Product $p) => $p->attachesTo($parent->product) && ! in_array($p->id, $taken, true))
            ->map(fn (Product $p) => ['product' => $p, 'price' => $p->prices->first(fn ($x) => $x->cycle === $parent->billing_cycle && $x->currency === $parent->currency)])
            ->filter(fn ($o) => $o['price'] !== null)
            ->map(fn ($o) => ['product' => $o['product'], 'amount' => $o['price']->amount])
            ->values();
    }

    /**
     * What adding an add-on would cost, without doing it.
     *
     * @return array{product: Product, price: ProductPrice, amount: int, charge: int, trial: bool, period_start: Carbon, period_end: Carbon, next_due_date: Carbon}
     */
    public function preview(Service $parent, Product $addon, bool $staff = false): array
    {
        $parent->loadMissing('product', 'client');
        if (! $this->canAttach($parent)) {
            throw new \DomainException('Add-ons can only be added to an active, recurring service that is not being cancelled.');
        }
        if (! $addon->isAddon() || ! $addon->is_active || (! $staff && ! $addon->is_visible)) {
            throw new \DomainException('That add-on is not available.');
        }
        if (! $addon->attachesTo($parent->product)) {
            throw new \DomainException('That add-on is not available for this plan.');
        }
        if (in_array($addon->id, $this->attachedProductIds($parent), true)) {
            throw new \DomainException('This service already has that add-on.');
        }
        $price = $addon->prices()->where('cycle', $parent->billing_cycle->value)->where('currency', $parent->currency)->first()
            ?? throw new \DomainException('That add-on has no '.strtolower($parent->billing_cycle->label()).' price in '.$parent->currency.'.');

        $today = now()->startOfDay();
        $end = $parent->next_due_date->copy()->startOfDay();
        $start = $end->copy()->subMonthsNoOverflow($parent->billing_cycle->months());
        $periodDays = max(1, (int) $start->diffInDays($end));
        $remaining = (int) max(0, min($periodDays, $today->diffInDays($end, false)));
        $trial = $parent->onTrial();
        $billed = InternalAccounts::is($parent->client) ? 0 : $price->amount;

        return [
            'product' => $addon,
            'price' => $price,
            'amount' => $billed,
            'list_amount' => $price->amount,
            'trial' => $trial,
            'charge' => $trial ? 0 : (int) round($billed * $remaining / $periodDays),
            'period_start' => $today,
            'period_end' => $end->copy()->subDay(),
            'next_due_date' => $end,
            'trial_ends_at' => $trial ? $parent->trial_ends_at : null,
        ];
    }

    /**
     * Attach an add-on. Returns the new service; its invoice (if there is a charge today) is on ->invoiceItems.
     *
     * @return array{service: Service, invoice: ?Invoice}
     */
    public function add(Service $parent, Product $addon, bool $staff = false, bool $send = true): array
    {
        $plan = $this->preview($parent, $addon, $staff);

        $result = DB::transaction(function () use ($parent, $addon, $plan, $send) {
            $service = Service::create([
                'client_id' => $parent->client_id,
                'product_id' => $addon->id,
                'parent_service_id' => $parent->id,
                'status' => ServiceStatus::Pending,
                'billing_cycle' => $parent->billing_cycle,
                'currency' => $parent->currency,
                'amount' => $plan['amount'],
                'internal_original_amount' => InternalAccounts::is($parent->client) ? $plan['list_amount'] : null,
                'next_due_date' => $plan['next_due_date'],
                'trial_ends_at' => $plan['trial_ends_at'],
            ]);

            $invoice = null;
            if ($plan['charge'] > 0) {
                $invoice = $this->invoices->create($parent->client, [[
                    'kind' => 'product',
                    'description' => $addon->name.' (add-on to '.$parent->title().') — '.$plan['period_start']->format('j M Y').' to '.$plan['period_end']->format('j M Y'),
                    'unit_price' => $plan['charge'],
                    'taxable' => ! $addon->tax_exempt,
                    'service_id' => $service->id,
                    'product_id' => $addon->id,
                ]], ['currency' => $parent->currency, 'issue' => true, 'send' => $send, 'due_date' => now()->toDateString()]);
            }

            return [$service, $invoice];
        });
        [$service, $invoice] = $result;

        event(new ServiceCreated($service));
        Activity::log('service.addon_added', $parent, 'Add-on '.$addon->name.' added');
        if (! $invoice) {
            ProvisionServiceJob::dispatch($service->id);   // nothing to pay today: trial, internal account or last day of the period
        }

        return ['service' => $service->refresh(), 'invoice' => $invoice?->refresh()];
    }

    /** @return array<int, int> product ids of this service's add-ons that are still around */
    private function attachedProductIds(Service $parent): array
    {
        return $parent->addons()->whereIn('status', ['pending', 'active', 'suspended'])->pluck('product_id')->map(fn ($id) => (int) $id)->all();
    }
}
