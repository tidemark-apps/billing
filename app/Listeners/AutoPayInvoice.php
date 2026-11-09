<?php

namespace App\Listeners;

use App\Events\InvoiceCreated;
use App\Models\Invoice;
use App\Services\AutoPayer;

/** An invoice was issued: if its schedule says it is already time (e.g. renewals charged at creation, or already due), autopay it. */
class AutoPayInvoice
{
    public function __construct(private readonly AutoPayer $autoPayer) {}

    public function handle(InvoiceCreated $event): void
    {
        if (! $event->model instanceof Invoice) {
            return;
        }
        try {
            $this->autoPayer->handleIssued($event->model);
        } catch (\Throwable $e) {
            report($e); // never break issuing an invoice because a charge attempt failed
        }
    }
}
