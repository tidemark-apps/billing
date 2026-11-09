<?php

namespace App\Services;

use App\Models\Client;
use App\Models\TaxRate;

class TaxService
{
    /** The rate that applies to a client: the best country/region match, otherwise the store-wide rate, otherwise none. */
    public function rateFor(Client $client): ?TaxRate
    {
        $rates = TaxRate::where('is_active', true)->get();

        $match = $rates->first(fn (TaxRate $r) => $r->country && $client->country && strcasecmp($r->country, $client->country) === 0
            && $r->region && $client->state && strcasecmp($r->region, $client->state) === 0);

        return $match
            ?? $rates->first(fn (TaxRate $r) => $r->country && $client->country && strcasecmp($r->country, $client->country) === 0 && ! $r->region)
            ?? $rates->first(fn (TaxRate $r) => ! $r->country);
    }
}
