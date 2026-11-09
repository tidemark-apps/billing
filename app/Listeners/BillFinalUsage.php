<?php

namespace App\Listeners;

use App\Events\ServiceTerminated;
use App\Services\BillingRunner;

/** A terminated or cancelled service gets a last invoice for any usage not yet billed. */
class BillFinalUsage
{
    public function __construct(private readonly BillingRunner $runner) {}

    public function handle(ServiceTerminated $event): void
    {
        try {
            $this->runner->billFinalUsage($event->model);
        } catch (\Throwable $e) {
            report($e); // never let a billing hiccup block terminating the service
        }
    }
}
