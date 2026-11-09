<?php

namespace App\Http\Resources;

use App\Models\UsageMeter;
use App\Support\UsageMath;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product's usage meter. Prices are integers in micro-minor units (1 = one millionth of the currency's minor unit, so
 * 0.002 USD is 200000), the same unit the admin screen stores; unit_price_display shows them in major units.
 *
 * @mixin UsageMeter
 */
class UsageMeterResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'key' => $this->key,
            'name' => $this->name,
            'unit' => $this->unit,
            'aggregation' => $this->aggregation,
            'included_quantity' => UsageMath::plain((string) $this->included_quantity),
            'pricing_model' => $this->pricing_model,
            'package_size' => $this->package_size !== null ? UsageMath::plain((string) $this->package_size) : null,
            'sort' => $this->sort,
            'prices' => $this->whenLoaded('prices', fn () => $this->prices->sortBy('currency')->map(fn ($p) => [
                'currency' => $p->currency,
                'unit_price' => (int) $p->unit_price,
                'unit_price_display' => UsageMath::microToMajor((int) $p->unit_price, $p->currency, 2),
                'tiers' => collect(UsageMath::sortTiers($p->tiers ?? []))->map(fn ($t) => [
                    'up_to' => ($t['up_to'] ?? null) === null || $t['up_to'] === '' ? null : UsageMath::plain(UsageMath::norm($t['up_to'])),
                    'unit_price' => (int) ($t['unit_price'] ?? 0),
                    'flat_fee' => (int) ($t['flat_fee'] ?? 0),
                ])->values()->all(),
            ])->values()->all()),
        ];
    }
}
