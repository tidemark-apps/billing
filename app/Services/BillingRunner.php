<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Events\TrialConverted;
use App\Events\TrialEnding;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** The recurring work: renewal invoices, reminders, suspending and terminating unpaid services. Run daily by the scheduler. */
class BillingRunner
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly ProvisioningService $provisioning,
        private readonly QuoteService $quotes,
        private readonly Notifier $notifier,
        private readonly UsageService $usage,
    ) {}

    /** @return array<string, int> */
    public function run(): array
    {
        return [
            'usage_pulled' => $this->usage->pullAll(),
            'plan_changes' => app(PlanChangeService::class)->applyDue(),
            'trial_reminders' => $this->sendTrialReminders(),
            'invoices' => $this->generateRenewalInvoices(),
            'reminders' => $this->sendReminders(),
            'suspended' => $this->suspendOverdue(),
            'terminated' => $this->terminateOverdue(),
            'cancelled' => $this->processCancellations(),
            'quotes_expired' => $this->quotes->expireOverdue(),
            'provisioning_retried' => $this->provisioning->retryPending(),
        ];
    }

    /**
     * One invoice per client per due date, covering every recurring service renewing that day.
     * Services with metered products also get one usage line per meter for the closing period (in arrears); these are invoiced
     * when the period ends (or billing.usage_invoice_days_before days earlier, default 0) so the whole period's usage is captured.
     */
    public function generateRenewalInvoices(): int
    {
        $horizon = now()->addDays((int) Settings::get('billing.invoice_days_before', 14))->toDateString();
        $usageHorizon = now()->addDays(max(0, (int) Settings::get('billing.usage_invoice_days_before', 0)))->toDateString();
        $trialHorizon = now()->addDays(max(0, (int) Settings::get('billing.trial_invoice_days_before', 0)))->toDateString();
        $due = Service::with(['client', 'product.meters'])
            ->where('status', ServiceStatus::Active->value)
            ->where('cancel_at_period_end', false)
            ->where('billing_cycle', '!=', 'one_time')
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<=', max($horizon, $usageHorizon, $trialHorizon))
            ->get()
            ->filter(fn (Service $s) => $s->next_due_date->toDateString() <= $this->invoiceHorizon($s, $horizon, $usageHorizon, $trialHorizon))
            ->filter(fn (Service $s) => ! $this->alreadyInvoiced($s))
            ->filter(fn (Service $s) => ! $this->awaitingPlanChange($s));

        $count = 0;
        foreach ($due->groupBy(fn (Service $s) => $s->client_id.'|'.$s->currency.'|'.$s->next_due_date->toDateString()) as $services) {
            $first = $services->first();
            DB::transaction(function () use ($services, $first) {
                $items = [];
                $settle = [];
                foreach ($services as $s) {
                    $start = $s->next_due_date->copy();
                    $end = $s->billing_cycle->advance($start)->subDay();
                    $trial = $s->trial_ends_at !== null && $s->trial_converted_at === null;
                    $items[] = [
                        'kind' => 'renewal',
                        'description' => $s->title().' — '.$start->format('j M Y').' to '.$end->format('j M Y'),
                        'unit_price' => $s->amount,
                        'taxable' => ! ($s->product?->tax_exempt ?? false),
                        'service_id' => $s->id,
                        'product_id' => $s->product_id,
                        'period_start' => $start->toDateString(),
                        'period_end' => $end->toDateString(),
                    ];
                    if ($trial && ($setup = $s->product?->priceFor($s->billing_cycle, $s->currency)?->setup_fee) > 0) {
                        $items[] = ['kind' => 'setup', 'description' => ($s->product?->name ?? $s->title()).' — setup fee', 'unit_price' => $setup, 'taxable' => ! ($s->product?->tax_exempt ?? false), 'service_id' => $s->id, 'product_id' => $s->product_id];
                    }
                    if ($this->isMetered($s)) {
                        // The closing period is [due - cycle, due). Anything recorded after the cut-off waits for the next invoice.
                        [$usageStart] = $this->usage->period($s);
                        $cutoff = now()->min($s->next_due_date->copy()->startOfDay());
                        $batch = $this->usage->collect($s, $cutoff, $usageStart, $s->next_due_date->copy()->subDay());
                        $settle[] = [$s, $batch['claims'], count($items)];
                        array_push($items, ...$batch['items']);
                    }
                }
                $invoice = $this->invoices->create($first->client, $items, ['currency' => $first->currency, 'issue' => true, 'due_date' => $first->next_due_date->toDateString()]);
                $this->settleUsage($invoice, $settle);
                foreach ($services as $s) {
                    if ($s->trial_ends_at !== null && $s->trial_converted_at === null) {
                        $s->update(['trial_converted_at' => now()]);
                        event(new TrialConverted($s, ['invoice_id' => $invoice->id]));
                    }
                }
            });
            $count++;
        }

        return $count;
    }

    /** Trials are invoiced when they end (billing.trial_invoice_days_before earlier, default 0), not 14 days ahead like renewals. */
    private function invoiceHorizon(Service $s, string $horizon, string $usageHorizon, string $trialHorizon): string
    {
        if ($s->trial_ends_at !== null && $s->trial_converted_at === null) {
            return $trialHorizon;
        }

        return $this->isMetered($s) ? $usageHorizon : $horizon;
    }

    /** A downgrade scheduled for the renewal date is applied that day, so the renewal invoice waits for it. */
    private function awaitingPlanChange(Service $s): bool
    {
        return $s->planChanges()->where('status', 'scheduled')->whereDate('effective_date', '>', now()->toDateString())->exists();
    }

    /** One "trial ending" email and event per trial, billing.trial_reminder_days (default 3) before it ends. */
    public function sendTrialReminders(): int
    {
        $days = (int) Settings::get('billing.trial_reminder_days', 3);
        if ($days < 0) {
            return 0;
        }
        $count = 0;
        Service::with('client', 'product')->where('status', ServiceStatus::Active->value)->whereNotNull('trial_ends_at')
            ->whereNull('trial_converted_at')->whereNull('trial_reminded_at')->where('cancel_at_period_end', false)
            ->whereDate('trial_ends_at', '<=', now()->addDays($days)->toDateString())->each(function (Service $s) use (&$count) {
                $s->update(['trial_reminded_at' => now()]);
                $this->notifier->trialEnding($s);
                event(new TrialEnding($s));
                $count++;
            });

        return $count;
    }

    /** Raise the first invoice for a trial right now (admin "end trial now"). */
    public function convertTrialNow(Service $service): void
    {
        if ($service->trial_ends_at === null || $service->trial_converted_at !== null) {
            return;
        }
        $service->update(['trial_ends_at' => now()->startOfDay(), 'next_due_date' => now()->startOfDay()]);
        $this->generateRenewalInvoices();
    }

    private function isMetered(Service $service): bool
    {
        return $service->product !== null && $service->product->meters->isNotEmpty();
    }

    /** @param  array<int, array{0: Service, 1: array<int, array<string, mixed>>, 2: int}>  $settle */
    private function settleUsage(Invoice $invoice, array $settle): void
    {
        $created = $invoice->items()->orderBy('sort')->get()->values();
        foreach ($settle as [$service, $claims, $offset]) {
            $map = [];
            foreach ($created->slice($offset) as $i => $item) {
                $map[$i - $offset] = $item;
            }
            $this->usage->settle($service, $claims, $map);
        }
    }

    /**
     * Bill whatever usage has not been invoiced yet when a service ends (terminated or cancelled). Idempotent: billed records are
     * stamped, so calling this again (cancel fires both a terminate and a cancel event) creates nothing.
     */
    public function billFinalUsage(Service $service): ?Invoice
    {
        $service->loadMissing(['client', 'product.meters']);
        if (! $this->isMetered($service) || ! $service->client) {
            return null;
        }

        return DB::transaction(function () use ($service) {
            [$start] = $this->usage->period($service);
            $batch = $this->usage->collect($service, now()->addSecond(), $start, now()->startOfDay());
            if (! $batch['items']) {
                $this->usage->settle($service, $batch['claims'], []);

                return null;
            }
            $invoice = $this->invoices->create($service->client, $batch['items'], ['currency' => $service->currency, 'issue' => true, 'due_date' => now()->toDateString()]);
            $this->settleUsage($invoice, [[$service, $batch['claims'], 0]]);

            return $invoice;
        });
    }

    private function alreadyInvoiced(Service $service): bool
    {
        return $service->invoiceItems()
            ->where('kind', 'renewal')
            ->whereDate('period_start', $service->next_due_date->toDateString())
            ->whereHas('invoice', fn ($q) => $q->whereIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Open->value, InvoiceStatus::Paid->value]))
            ->exists();
    }

    /** Reminders at configurable offsets from the due date (default: 3 days before, then 1, 7 and 14 days after). */
    public function sendReminders(): int
    {
        $offsets = array_map('intval', array_filter(explode(',', (string) Settings::get('billing.reminder_days', '-3,1,7,14')), 'strlen'));
        sort($offsets);
        if (! $offsets) {
            return 0;
        }
        $sent = 0;
        Invoice::with('client')->where('status', InvoiceStatus::Open->value)->whereNotNull('due_date')->each(function (Invoice $invoice) use ($offsets, &$sent) {
            $daysAfterDue = (int) $invoice->due_date->startOfDay()->diffInDays(Carbon::today(), false);
            $next = $offsets[$invoice->reminder_count] ?? null;
            if ($next !== null && $daysAfterDue >= $next) {
                $this->notifier->invoiceReminder($invoice);
                $invoice->update(['reminder_count' => $invoice->reminder_count + 1, 'last_reminder_at' => now()]);
                $sent++;
            }
        });

        return $sent;
    }

    public function suspendOverdue(): int
    {
        $days = (int) Settings::get('billing.suspend_after_days', 5);

        return $days > 0 ? $this->actOnOverdue($days, ServiceStatus::Active, fn (Service $s) => $this->provisioning->suspend($s, 'Overdue invoice')) : 0;
    }

    public function terminateOverdue(): int
    {
        $days = (int) Settings::get('billing.terminate_after_days', 30);

        return $days > 0 ? $this->actOnOverdue($days, ServiceStatus::Suspended, fn (Service $s) => $this->provisioning->terminate($s)) : 0;
    }

    private function actOnOverdue(int $days, ServiceStatus $from, \Closure $act): int
    {
        $cutoff = now()->subDays($days)->toDateString();
        $count = 0;
        $serviceIds = InvoiceItem::where('kind', 'renewal')
            ->whereHas('invoice', fn ($q) => $q->where('status', InvoiceStatus::Open->value)->whereDate('due_date', '<=', $cutoff))
            ->pluck('service_id')->filter()->unique();
        Service::whereIn('id', $serviceIds)->where('status', $from->value)->each(function (Service $s) use ($act, &$count) {
            $act($s);
            $count++;
        });

        return $count;
    }

    /** Services flagged "cancel at period end" are cancelled once their renewal date arrives. */
    public function processCancellations(): int
    {
        $count = 0;
        Service::where('cancel_at_period_end', true)->whereIn('status', [ServiceStatus::Active->value, ServiceStatus::Suspended->value])
            ->whereDate('next_due_date', '<=', now()->toDateString())->each(function (Service $s) use (&$count) {
                $this->provisioning->cancel($s, immediately: true, reason: $s->cancel_reason);
                $count++;
            });

        return $count;
    }
}
