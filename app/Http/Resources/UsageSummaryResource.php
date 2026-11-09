<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsMoney;
use App\Support\UsageMath;
use App\Support\UsageSummary;
use Illuminate\Http\Resources\Json\JsonResource;

/** Unbilled usage of the current period with its estimated charge (before tax). Only meters that have usage appear. @mixin UsageSummary */
class UsageSummaryResource extends JsonResource
{
    use FormatsMoney;

    public function toArray($request): array
    {
        return [
            'period_start' => $this->periodStart?->toDateString(),
            'period_end' => $this->periodEnd?->toDateString(),
            'currency' => $this->currency,
            'estimated_charge' => $this->money($this->total(), $this->currency),
            'meters' => $this->lines->map(fn ($l) => [
                'meter' => $l->meter->key,
                'name' => $l->meter->name,
                'unit' => $l->meter->unit,
                'aggregation' => $l->meter->aggregation,
                'quantity' => UsageMath::plain($l->quantity),
                'included' => UsageMath::plain($l->included),
                'billable' => UsageMath::plain($l->billable),
                'records' => $l->records,
                'priced' => $l->priced,
                'estimated_charge' => $this->money($l->amount, $l->currency),
            ])->values()->all(),
        ];
    }
}
