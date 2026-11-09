<?php

namespace App\Http\Resources;

use App\Models\UsageRecord;
use App\Support\UsageMath;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UsageRecord */
class UsageRecordResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'service_id' => $this->service_id,
            'meter' => $this->whenLoaded('meter', fn () => $this->meter->key),
            'meter_id' => $this->meter_id,
            'quantity' => UsageMath::plain((string) $this->quantity),
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'idempotency_key' => $this->idempotency_key,
            'source' => $this->source,
            'invoiced' => $this->isInvoiced(),
            'invoiced_at' => $this->invoiced_at?->toIso8601String(),
            'invoice_item_id' => $this->invoice_item_id,
            'metadata' => $this->metadata ?: new \stdClass,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
