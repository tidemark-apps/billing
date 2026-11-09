<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsMoney;
use App\Models\Service;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Service */
class ServiceResource extends JsonResource
{
    use FormatsMoney;

    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'client_id' => $this->client_id,
            'product_id' => $this->product_id,
            'status' => $this->status->value,
            'label' => $this->label,
            'billing_cycle' => $this->billing_cycle->value,
            'price' => $this->money($this->amount, $this->currency),
            'next_due_date' => $this->next_due_date?->toDateString(),
            'cancel_at_period_end' => $this->cancel_at_period_end,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'terminated_at' => $this->terminated_at?->toIso8601String(),
            'provision' => $this->provision ?: new \stdClass,
            'provision_error' => $this->when($this->isStaffRequest($request), $this->provision_error),
            'notes' => $this->when($this->isStaffRequest($request), $this->notes),
            'product' => new ProductResource($this->whenLoaded('product')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
