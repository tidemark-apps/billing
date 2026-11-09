<?php

namespace App\Contracts;

use App\Models\Service;
use Carbon\CarbonInterface;

/**
 * Optional capability of a provisioning module: report metered usage from the remote system (bandwidth, disk, API calls).
 * `billing:usage:pull` (also run by `billing:run`) calls it daily for every live service whose product has meters.
 */
interface ReportsUsage
{
    /**
     * Usage between $from (inclusive) and $to (exclusive) for the service.
     *
     * @return array<string, float|int|string> meter key => quantity. For "sum" meters the quantity consumed in the window;
     *                                         for "max" / "last" meters the observed value. Unknown keys are ignored.
     */
    public function pullUsage(Service $service, CarbonInterface $from, CarbonInterface $to): array;
}
