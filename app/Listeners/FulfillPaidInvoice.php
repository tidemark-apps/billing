<?php

namespace App\Listeners;

use App\Enums\ServiceStatus;
use App\Events\InvoicePaid;
use App\Jobs\ProvisionServiceJob;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\ProvisioningService;

/** When an invoice is paid: provision the services it ordered, and extend (and unsuspend) the ones it renewed. */
class FulfillPaidInvoice
{
    public function __construct(private readonly ProvisioningService $provisioning) {}

    public function handle(InvoicePaid $event): void
    {
        /** @var Invoice $invoice */
        $invoice = $event->model;
        $invoice->load('items.service.product');

        foreach ($invoice->items as $item) {
            $service = $item->service;
            if (! $service) {
                continue;
            }
            if ($item->kind === 'product' && $service->status === ServiceStatus::Pending) {
                ProvisionServiceJob::dispatch($service->id);
            } elseif ($item->kind === 'renewal') {
                $this->renew($item);
            }
        }
    }

    private function renew(InvoiceItem $item): void
    {
        $service = $item->service;
        if (! $service->status->isLive()) {
            return;
        }
        $next = $item->period_end ? $item->period_end->copy()->addDay() : $service->billing_cycle->advance($service->next_due_date ?? now());
        if (! $service->next_due_date || $next->gt($service->next_due_date)) {
            $service->update(['next_due_date' => $next]);
        }
        if ($service->status === ServiceStatus::Suspended) {
            $this->provisioning->unsuspend($service);
        }
    }
}
