<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsMoney;
use App\Models\PlanChange;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PlanChange */
class PlanChangeResource extends JsonResource
{
    use FormatsMoney;

    public function toArray($request): array
    {
        $c = $this->currency;
        $product = fn ($p) => $p ? ['id' => $p->id, 'name' => $p->name] : null;

        return [
            'id' => $this->id,
            'service_id' => $this->service_id,
            'direction' => $this->direction,
            'timing' => $this->timing,
            'status' => $this->status,
            'source' => $this->source,
            'from_product' => $product($this->fromProduct),
            'to_product' => $product($this->toProduct),
            'from_cycle' => $this->from_cycle->value,
            'to_cycle' => $this->to_cycle->value,
            'from_amount' => $this->money($this->from_amount, $c),
            'to_amount' => $this->money($this->to_amount, $c),
            'credit' => $this->money($this->credit, $c),
            'charge' => $this->money($this->charge, $c),
            'effective_date' => $this->effective_date?->toDateString(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'invoice' => $this->invoice ? [
                'id' => $this->invoice->id,
                'uuid' => $this->invoice->uuid,
                'number' => $this->invoice->number,
                'status' => $this->invoice->status->value,
                'total' => $this->money($this->invoice->total, $this->invoice->currency),
                'balance' => $this->money($this->invoice->balance(), $this->invoice->currency),
                'due_date' => $this->invoice->due_date?->toDateString(),
                'pay_url' => route('invoice.public', $this->invoice->uuid),
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
