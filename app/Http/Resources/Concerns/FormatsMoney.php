<?php

namespace App\Http\Resources\Concerns;

use App\Support\Money;

trait FormatsMoney
{
    /** @return array{amount: int, currency: string, formatted: string} */
    protected function money(?int $amount, string $currency): array
    {
        return ['amount' => (int) $amount, 'currency' => $currency, 'formatted' => Money::format((int) $amount, $currency)];
    }

    protected function isStaffRequest($request): bool
    {
        return (bool) $request?->user()?->isStaff();
    }
}
