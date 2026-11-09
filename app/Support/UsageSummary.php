<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class UsageSummary
{
    /** @param  Collection<int, UsageCharge>  $lines */
    public function __construct(
        public readonly ?CarbonInterface $periodStart,
        public readonly ?CarbonInterface $periodEnd,
        public readonly string $currency,
        public readonly Collection $lines,
    ) {}

    /** Estimated charge in minor units (before tax). */
    public function total(): int
    {
        return (int) $this->lines->sum('amount');
    }
}
