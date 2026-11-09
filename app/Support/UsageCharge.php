<?php

namespace App\Support;

use App\Models\UsageMeter;

/** The priced result for one meter over one period: what was used, what was free, and what it costs. */
final class UsageCharge
{
    public function __construct(
        public readonly UsageMeter $meter,
        public readonly string $quantity,   // aggregated, 6 decimals
        public readonly string $included,
        public readonly string $billable,
        public readonly string $micro,      // exact cost in micro-minor units (integer string)
        public readonly int $amount,        // cost rounded to minor units
        public readonly string $currency,
        public readonly bool $priced,       // false when the meter has no price for this currency
        public readonly int $records = 0,
    ) {}

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    /** Human-readable breakdown used as the invoice line description. */
    public function describe(): string
    {
        $unit = $this->meter->unit;
        $parts = [UsageMath::display($this->quantity).' '.$unit];
        if (! UsageMath::isZero($this->included)) {
            $parts[] = UsageMath::display($this->included).' included';
        }
        $price = $this->meter->priceFor($this->currency);
        $c = $this->currency;
        $bill = UsageMath::display($this->billable);
        if (! $this->priced || ! $price) {
            $parts[] = 'no price set';
        } elseif ($this->meter->pricing_model === 'per_unit') {
            $parts[] = $bill.' × '.UsageMath::microToMajor($price->unit_price, $c, 2).' '.$c;
        } elseif ($this->meter->pricing_model === 'package') {
            $parts[] = $bill.' '.$unit.' in packages of '.UsageMath::display((string) $this->meter->package_size).' at '.UsageMath::microToMajor($price->unit_price, $c, 2).' '.$c.' each';
        } else {
            $parts[] = $bill.' '.$unit.' billed in graduated tiers';
        }

        return implode(', ', $parts);
    }
}
