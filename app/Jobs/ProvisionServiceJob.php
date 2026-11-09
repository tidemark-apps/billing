<?php

namespace App\Jobs;

use App\Models\Service;
use App\Services\ProvisioningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionServiceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $serviceId) {}

    public function handle(ProvisioningService $provisioning): void
    {
        if ($service = Service::find($this->serviceId)) {
            $provisioning->activate($service);
        }
    }
}
