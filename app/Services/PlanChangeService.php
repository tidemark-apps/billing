<?php

namespace App\Services;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Events\PlanChanged;
use App\Events\PlanChangeScheduled;
use App\Models\PlanChange;
use App\Models\Product;
use App\Models\Service;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Upgrades, downgrades and billing-cycle changes.
 *
 * Immediate changes are prorated by the day: the unused part of the current period is credited and the rest of the period is
 * charged at the new price, on one invoice. Changing the billing cycle starts a new period today instead (credit the unused part,
 * charge one full new-cycle period). Changes can also be scheduled for the next renewal, with no proration. By default upgrades
 * happen now and downgrades at renewal (so the customer keeps what they paid for); staff can force either. A credit larger than
 * the new charge is not refunded or carried forward (there is no account-credit ledger): the preview says so.
 */
class PlanChangeService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly ProvisioningService $provisioning,
        private readonly Notifier $notifier,
    ) {}

    /** Whether the customer may change this service themselves. */
    public function customerCanChange(Service $service): bool
    {
        return (bool) Settings::get('billing.customer_plan_changes', true)
            && $this->changeable($service)
            && (bool) ($service->product?->allow_plan_changes ?? false);
    }

    public function changeable(Service $service): bool
    {
        return $service->status === ServiceStatus::Active
            && ! $service->cancel_at_period_end
            && $service->billing_cycle->isRecurring()
            && $service->next_due_date !== null
            && $service->product !== null;
    }

    /**
     * What a customer can switch to: every other active, visible product in the same group (and this product's other billing cycles)
     * that has a price in the service's currency.
     *
     * @return Collection<int, array{product: Product, cycle: BillingCycle, amount: int}>
     */
    public function options(Service $service, bool $staff = false): Collection
    {
        $current = $service->product;
        if (! $current) {
            return collect();
        }
        $products = Product::with('prices')->where('is_active', true)
            ->when(! $staff, fn ($q) => $q->where('is_visible', true)->where('product_group_id', $current->product_group_id))
            ->orderBy('sort')->orderBy('name')->get();

        $out = collect();
        foreach ($products as $product) {
            foreach ($product->pricesIn($service->currency) as $price) {
                if (! $price->cycle->isRecurring()) {
                    continue;
                }
                if ($product->id === $current->id && $price->cycle === $service->billing_cycle) {
                    continue;
                }
                $out->push(['product' => $product, 'cycle' => $price->cycle, 'amount' => $price->amount]);
            }
        }

        return $out;
    }

    /**
     * Work out a change without making it.
     *
     * @return array<string, mixed> direction, timing, credit, charge, amount_due, forfeited, effective_date, next_due_date, lines, ...
     */
    public function preview(Service $service, Product $to, BillingCycle $cycle, ?string $timing = null, bool $staff = false): array
    {
        $service->loadMissing('product');
        if (! $this->changeable($service)) {
            throw new \DomainException('Only an active, recurring service that is not being cancelled can change plan.');
        }
        if (! $to->is_active) {
            throw new \DomainException('That plan is not available.');
        }
        if (! $staff && (! $to->is_visible || $to->product_group_id !== $service->product->product_group_id)) {
            throw new \DomainException('That plan is not available for this service.');
        }
        if (! $cycle->isRecurring()) {
            throw new \DomainException('Choose a recurring billing cycle.');
        }
        if ($to->id === $service->product_id && $cycle === $service->billing_cycle) {
            throw new \DomainException('The service is already on that plan.');
        }
        $price = $to->prices()->where('cycle', $cycle->value)->where('currency', $service->currency)->first()
            ?? throw new \DomainException('That plan has no '.strtolower($cycle->label()).' price in '.$service->currency.'.');

        $service->loadMissing('client');
        $internal = InternalAccounts::is($service->client);
        $old = $service->amount;
        $listOld = $internal ? (int) ($service->internal_original_amount ?? 0) : $old;
        $billed = $internal ? 0 : $price->amount;  // internal accounts pay nothing, whatever the plan
        $direction = $to->id === $service->product_id ? 'change' : $this->direction($listOld, $service->billing_cycle, $price->amount, $cycle);
        $timing ??= $direction === 'downgrade' ? 'renewal' : 'immediate';
        if (! in_array($timing, ['immediate', 'renewal'], true)) {
            throw new \DomainException('Timing must be "immediate" or "renewal".');
        }
        if (! $staff && $direction === 'downgrade' && $timing === 'immediate') {
            throw new \DomainException('Downgrades take effect at the next renewal.');
        }

        $today = now()->startOfDay();
        $end = $service->next_due_date->copy()->startOfDay();
        $start = $service->billing_cycle->months() > 0 ? $end->copy()->subMonthsNoOverflow($service->billing_cycle->months()) : $end->copy();
        $periodDays = max(1, (int) $start->diffInDays($end));
        $remaining = (int) max(0, min($periodDays, $today->diffInDays($end, false)));
        $fraction = $remaining / $periodDays;

        $trial = $service->onTrial();
        $cycleChanged = $cycle !== $service->billing_cycle;
        $credit = $charge = 0;
        $nextDue = $service->next_due_date->copy();
        if ($timing === 'renewal') {
            // Nothing is prorated: the new plan simply starts with the next period.
        } elseif ($trial) {
            // Nothing has been paid yet, so there is nothing to prorate: the trial carries on with the new plan.
        } else {
            $credit = (int) round($old * $fraction);
            if ($cycleChanged) {
                $charge = $billed;
                $nextDue = $cycle->advance($today);
            } else {
                $charge = (int) round($billed * $fraction);
            }
        }

        $due = max(0, $charge - $credit);
        $effective = $timing === 'renewal' ? $end : $today;

        return [
            'direction' => $direction,
            'timing' => $timing,
            'trial' => $trial,
            'from_product' => $service->product,
            'to_product' => $to,
            'from_cycle' => $service->billing_cycle,
            'to_cycle' => $cycle,
            'currency' => $service->currency,
            'from_amount' => $old,
            'to_amount' => $billed,
            'list_amount' => $price->amount,
            'credit' => $credit,
            'charge' => $charge,
            'amount_due' => $due,
            'forfeited' => max(0, $credit - $charge),
            'effective_date' => $effective,
            'next_due_date' => $nextDue,
            'period_start' => $today,
            'period_end' => $cycleChanged ? $nextDue->copy()->subDay() : $end->copy()->subDay(),
            'old_period_start' => $start,
            'old_period_end' => $end->copy()->subDay(),
            'price' => $price,
        ];
    }

    /**
     * Make the change (or schedule it). Returns the recorded PlanChange; its invoice, if any, is on ->invoice.
     *
     * @param  'admin'|'customer'|'api'  $source
     */
    public function change(Service $service, Product $to, BillingCycle $cycle, ?string $timing = null, string $source = 'admin'): PlanChange
    {
        $staff = $source !== 'customer';
        $plan = $this->preview($service, $to, $cycle, $timing, $staff);

        if ($plan['timing'] === 'renewal') {
            return DB::transaction(function () use ($service, $plan, $source) {
                $service->planChanges()->where('status', 'scheduled')->update(['status' => 'cancelled']);
                $change = $this->record($service, $plan, $source, 'scheduled');
                Activity::log('service.plan_change_scheduled', $service, 'Plan change to '.$plan['to_product']->name.' scheduled for '.$plan['effective_date']->toDateString());
                event(new PlanChangeScheduled($service, ['plan_change_id' => $change->id]));
                $this->notifier->planChanged($service, $change);

                return $change;
            });
        }

        return $this->apply($service, $plan, $source);
    }

    /** Drop a change that is waiting for the renewal. */
    public function cancelScheduled(Service $service): bool
    {
        $n = $service->planChanges()->where('status', 'scheduled')->update(['status' => 'cancelled']);
        if ($n) {
            Activity::log('service.plan_change_cancelled', $service, 'Scheduled plan change cancelled');
        }

        return $n > 0;
    }

    /** Apply scheduled changes whose renewal date has arrived. */
    public function applyDue(): int
    {
        $count = 0;
        PlanChange::with('service.product')->where('status', 'scheduled')->whereDate('effective_date', '<=', now()->toDateString())->each(function (PlanChange $change) use (&$count) {
            $service = $change->service;
            $to = Product::withTrashed()->find($change->to_product_id);
            if (! $service || ! $to || $service->status !== ServiceStatus::Active) {
                if ($service && ! $service->status->isLive()) {
                    $change->update(['status' => 'cancelled']);
                }

                return;
            }
            try {
                $plan = $this->preview($service, $to, $change->to_cycle, 'renewal', true);
            } catch (\DomainException) {
                $change->update(['status' => 'cancelled']);

                return;
            }
            // At the renewal date the new period simply starts on the new plan, so nothing is prorated.
            $plan['timing'] = 'immediate';
            $plan['credit'] = $plan['charge'] = $plan['amount_due'] = 0;
            $plan['next_due_date'] = $service->next_due_date->copy();
            $this->apply($service, $plan, $change->source, $change);
            $count++;
        });

        return $count;
    }

    /** @param  array<string, mixed>  $plan */
    private function apply(Service $service, array $plan, string $source, ?PlanChange $existing = null): PlanChange
    {
        /** @var Product $from */
        $from = $service->product;
        /** @var Product $to */
        $to = $plan['to_product'];

        // Usage on the old plan's meters is billed before they stop applying.
        if ($from->id !== $to->id && $from->meters()->exists() && ! $plan['trial']) {
            app(BillingRunner::class)->billFinalUsage($service);
        }

        $change = DB::transaction(function () use ($service, $plan, $source, $existing, $from, $to) {
            $invoice = null;
            if ($plan['amount_due'] > 0) {
                $items = [];
                if ($plan['credit'] > 0) {
                    $items[] = ['kind' => 'credit', 'description' => 'Credit: unused '.$from->name.' ('.$plan['old_period_start']->copy()->max(now()->startOfDay())->format('j M Y').' to '.$plan['old_period_end']->format('j M Y').')', 'unit_price' => -$plan['credit'], 'taxable' => ! $from->tax_exempt, 'service_id' => $service->id, 'product_id' => $from->id];
                }
                $items[] = ['kind' => 'plan_change', 'description' => $to->name.' ('.$plan['to_cycle']->label().') — '.$plan['period_start']->format('j M Y').' to '.$plan['period_end']->format('j M Y'), 'unit_price' => $plan['charge'], 'taxable' => ! $to->tax_exempt, 'service_id' => $service->id, 'product_id' => $to->id, 'period_start' => $plan['period_start']->toDateString(), 'period_end' => $plan['period_end']->toDateString()];
                $invoice = $this->invoices->create($service->client, $items, ['currency' => $service->currency, 'issue' => true, 'due_date' => now()->toDateString()]);
            }

            $service->update([
                'product_id' => $to->id,
                'amount' => $plan['to_amount'],
                'internal_original_amount' => InternalAccounts::is($service->client) ? $plan['list_amount'] : null,
                'billing_cycle' => $plan['to_cycle'],
                'next_due_date' => $plan['next_due_date'],
            ]);

            if ($existing) {
                $existing->update(['status' => 'applied', 'applied_at' => now(), 'invoice_id' => $invoice?->id]);

                return $existing;
            }
            $change = $this->record($service, $plan, $source, 'applied');
            $change->update(['invoice_id' => $invoice?->id, 'applied_at' => now()]);

            return $change;
        });

        $service->refresh()->setRelation('product', $to);
        $this->provisioning->changePackage($service);
        Activity::log('service.plan_changed', $service, 'Moved from '.$from->name.' to '.$to->name.' ('.$plan['to_cycle']->label().')');
        event(new PlanChanged($service, ['plan_change_id' => $change->id]));
        $this->notifier->planChanged($service, $change->refresh());

        return $change->refresh();
    }

    /** @param  array<string, mixed>  $plan */
    private function record(Service $service, array $plan, string $source, string $status): PlanChange
    {
        return PlanChange::create([
            'service_id' => $service->id,
            'user_id' => auth()->id(),
            'from_product_id' => $service->product_id,
            'to_product_id' => $plan['to_product']->id,
            'from_cycle' => $plan['from_cycle'],
            'to_cycle' => $plan['to_cycle'],
            'currency' => $plan['currency'],
            'from_amount' => $plan['from_amount'],
            'to_amount' => $plan['to_amount'],
            'direction' => $plan['direction'],
            'timing' => $status === 'scheduled' ? 'renewal' : 'immediate',
            'status' => $status,
            'credit' => $plan['credit'],
            'charge' => $plan['charge'],
            'effective_date' => $plan['effective_date'],
            'source' => $source,
        ]);
    }

    /** Between different products, compare by price per month (a cycle change on the same product is always a plain "change"). */
    private function direction(int $oldAmount, BillingCycle $oldCycle, int $newAmount, BillingCycle $newCycle): string
    {
        $old = $oldAmount / max(1, $oldCycle->months());
        $new = $newAmount / max(1, $newCycle->months());

        return $new > $old + 0.0001 ? 'upgrade' : ($new < $old - 0.0001 ? 'downgrade' : 'change');
    }
}
