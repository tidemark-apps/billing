<?php

namespace App\Services;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Events\ServiceCreated;
use App\Events\TrialStarted;
use App\Jobs\ProvisionServiceJob;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(private readonly InvoiceService $invoices, private readonly Notifier $notifier) {}

    /**
     * Order a product: creates a pending service and an open invoice for it (price plus any setup fee).
     * When the invoice is paid the service is activated and provisioned automatically.
     *
     * A product with a trial (and a recurring cycle) starts the trial instead: the service is provisioned at once, nothing is
     * invoiced, and the first invoice is raised when the trial ends. Pass 'trial' => false to skip the trial and bill straight away.
     * Trials are once per customer per product; a customer who already had one is simply billed.
     *
     * @param  array{label?: string, config?: array<string, mixed>, send?: bool, trial?: bool, require_payment_method?: bool, addons?: array<int, string|int>}  $options  `addons` are add-on slugs or ids ordered together with the plan
     * @return array{service: Service, invoice: ?Invoice} invoice is null when a trial started
     */
    public function place(Client $client, Product $product, BillingCycle $cycle, array $options = []): array
    {
        if (! $product->is_active) {
            throw new \DomainException('This product is not available.');
        }
        if ($product->isAddon()) {
            throw new \DomainException('Add-ons are added to an existing service, not ordered on their own.');
        }
        $price = $product->priceFor($cycle, $client->currency)
            ?? throw new \DomainException('This product has no price for the selected billing cycle.');
        $addons = app(AddonService::class)->resolveForOrder($product, $cycle, $price->currency, $options['addons'] ?? []);
        if ($addons->isNotEmpty() && ! $cycle->isRecurring()) {
            throw new \DomainException('Add-ons need a recurring billing cycle.');
        }
        $options['resolved_addons'] = $addons;

        if (($options['trial'] ?? true) && $cycle->isRecurring() && $product->trialAvailableTo($client)) {
            return $this->startTrial($client, $product, $cycle, $price, $options);
        }

        return DB::transaction(function () use ($client, $product, $cycle, $price, $options) {
            $service = Service::create([
                'client_id' => $client->id,
                'product_id' => $product->id,
                'status' => ServiceStatus::Pending,
                'label' => $options['label'] ?? null,
                'billing_cycle' => $cycle,
                'currency' => $price->currency,
                ...InternalAccounts::pricing($client, $price->amount),
                'config' => $options['config'] ?? null,
            ]);

            $items = [[
                'kind' => 'product',
                'description' => $product->name.($cycle->isRecurring() ? ' ('.$cycle->label().')' : '').($service->label ? ' — '.$service->label : ''),
                'unit_price' => $service->amount,
                'taxable' => ! $product->tax_exempt,
                'service_id' => $service->id,
                'product_id' => $product->id,
            ]];
            if ($price->setup_fee > 0 && ! InternalAccounts::is($client)) {
                $items[] = ['kind' => 'setup', 'description' => $product->name.' — setup fee', 'unit_price' => $price->setup_fee, 'taxable' => ! $product->tax_exempt, 'service_id' => $service->id, 'product_id' => $product->id];
            }

            foreach ($options['resolved_addons'] as $a) {
                $child = Service::create([
                    'client_id' => $client->id,
                    'product_id' => $a['product']->id,
                    'parent_service_id' => $service->id,
                    'status' => ServiceStatus::Pending,
                    'billing_cycle' => $cycle,
                    'currency' => $a['price']->currency,
                    ...InternalAccounts::pricing($client, $a['price']->amount),
                ]);
                $items[] = [
                    'kind' => 'product',
                    'description' => $a['product']->name.' (add-on, '.strtolower($cycle->label()).') — '.($service->label ?: $product->name),
                    'unit_price' => $child->amount,
                    'taxable' => ! $a['product']->tax_exempt,
                    'service_id' => $child->id,
                    'product_id' => $a['product']->id,
                ];
            }
            $invoice = $this->invoices->create($client, $items, [
                'currency' => $price->currency,
                'issue' => true,
                'send' => $options['send'] ?? true,
                'due_date' => now()->toDateString(),
            ]);
            event(new ServiceCreated($service));

            return ['service' => $service->refresh(), 'invoice' => $invoice->refresh()];
        });
    }

    /** @return array{service: Service, invoice: null} */
    private function startTrial(Client $client, Product $product, BillingCycle $cycle, ProductPrice $price, array $options): array
    {
        if ($product->trial_requires_payment_method && ($options['require_payment_method'] ?? true)
            && ! PaymentMethod::where('client_id', $client->id)->exists()) {
            throw new \DomainException('Add a payment method before starting this trial.');
        }
        $ends = now()->addDays($product->trial_days)->startOfDay();

        $children = [];
        $service = DB::transaction(function () use ($client, $product, $cycle, $price, $options, $ends, &$children) {
            $service = Service::create([
                'client_id' => $client->id,
                'product_id' => $product->id,
                'status' => ServiceStatus::Pending,
                'label' => $options['label'] ?? null,
                'billing_cycle' => $cycle,
                'currency' => $price->currency,
                ...InternalAccounts::pricing($client, $price->amount),
                'config' => $options['config'] ?? null,
                'trial_ends_at' => $ends,
                'next_due_date' => $ends,
            ]);
            // Add-ons ordered with the plan are free until the trial ends, like add-ons attached during it.
            foreach ($options['resolved_addons'] ?? [] as $a) {
                $children[] = Service::create([
                    'client_id' => $client->id,
                    'product_id' => $a['product']->id,
                    'parent_service_id' => $service->id,
                    'status' => ServiceStatus::Pending,
                    'billing_cycle' => $cycle,
                    'currency' => $a['price']->currency,
                    ...InternalAccounts::pricing($client, $a['price']->amount),
                    'trial_ends_at' => $ends,
                    'next_due_date' => $ends,
                ]);
            }

            return $service;
        });
        event(new ServiceCreated($service));
        foreach ($children as $child) {
            event(new ServiceCreated($child));
        }
        event(new TrialStarted($service, ['days' => $product->trial_days]));
        ProvisionServiceJob::dispatch($service->id);
        foreach ($children as $child) {
            ProvisionServiceJob::dispatch($child->id);
        }
        $service = $service->refresh();
        if ($options['send'] ?? true) {
            $this->notifier->trialStarted($service);
        }

        return ['service' => $service, 'invoice' => null];
    }
}
