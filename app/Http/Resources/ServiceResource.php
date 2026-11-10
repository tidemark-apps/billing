<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsMoney;
use App\Models\Service;
use App\Services\PlanChangeService;
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
            'on_trial' => $this->onTrial(),
            'trial_ends_at' => $this->trial_ends_at?->toDateString(),
            'trial_converted_at' => $this->trial_converted_at?->toIso8601String(),
            'scheduled_change' => $this->scheduledChangeSummary(),
            'can_change_plan' => $this->when(! $this->isStaffRequest($request), fn () => app(PlanChangeService::class)->customerCanChange($this->resource)),
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

    /** @return array{id: int, to_product: array{id: int, name: string}|null, to_cycle: string, to_amount: array<string, mixed>, effective_date: string|null}|null */
    private function scheduledChangeSummary(): ?array
    {
        $change = $this->relationLoaded('planChanges') ? $this->planChanges->firstWhere('status', 'scheduled') : $this->scheduledChange();
        if (! $change) {
            return null;
        }
        $to = $change->toProduct;

        return [
            'id' => $change->id,
            'to_product' => $to ? ['id' => $to->id, 'name' => $to->name] : null,
            'to_cycle' => $change->to_cycle->value,
            'to_amount' => $this->money($change->to_amount, $change->currency),
            'effective_date' => $change->effective_date?->toDateString(),
        ];
    }
}
