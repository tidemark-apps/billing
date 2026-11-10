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
     * @param  array{label?: string, config?: array<string, mixed>, send?: bool, trial?: bool, require_payment_method?: bool}  $options
     * @return array{service: Service, invoice: ?Invoice} invoice is null when a trial started
     */
    public function place(Client $client, Product $product, BillingCycle $cycle, array $options = []): array
    {
        if (! $product->is_active) {
            throw new \DomainException('This product is not available.');
        }
        $price = $product->priceFor($cycle, $client->currency)
            ?? throw new \DomainException('This product has no price for the selected billing cycle.');

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
                'amount' => $price->amount,
                'config' => $options['config'] ?? null,
            ]);

            $items = [[
                'kind' => 'product',
                'description' => $product->name.($cycle->isRecurring() ? ' ('.$cycle->label().')' : '').($service->label ? ' — '.$service->label : ''),
                'unit_price' => $price->amount,
                'taxable' => ! $product->tax_exempt,
                'service_id' => $service->id,
                'product_id' => $product->id,
            ]];
            if ($price->setup_fee > 0) {
                $items[] = ['kind' => 'setup', 'description' => $product->name.' — setup fee', 'unit_price' => $price->setup_fee, 'taxable' => ! $product->tax_exempt, 'service_id' => $service->id, 'product_id' => $product->id];
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

        $service = DB::transaction(fn () => Service::create([
            'client_id' => $client->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Pending,
            'label' => $options['label'] ?? null,
            'billing_cycle' => $cycle,
            'currency' => $price->currency,
            'amount' => $price->amount,
            'config' => $options['config'] ?? null,
            'trial_ends_at' => $ends,
            'next_due_date' => $ends,
        ]));
        event(new ServiceCreated($service));
        event(new TrialStarted($service, ['days' => $product->trial_days]));
        ProvisionServiceJob::dispatch($service->id);
        $service = $service->refresh();
        if ($options['send'] ?? true) {
            $this->notifier->trialStarted($service);
        }

        return ['service' => $service, 'invoice' => null];
    }
}
