<?php

namespace App\Services;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Events\ServiceCreated;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(private readonly InvoiceService $invoices) {}

    /**
     * Order a product: creates a pending service and an open invoice for it (price plus any setup fee).
     * When the invoice is paid the service is activated and provisioned automatically.
     *
     * @param  array{label?: string, config?: array<string, mixed>, send?: bool}  $options
     * @return array{service: Service, invoice: Invoice}
     */
    public function place(Client $client, Product $product, BillingCycle $cycle, array $options = []): array
    {
        if (! $product->is_active) {
            throw new \DomainException('This product is not available.');
        }
        $price = $product->priceFor($cycle, $client->currency)
            ?? throw new \DomainException('This product has no price for the selected billing cycle.');

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
}
