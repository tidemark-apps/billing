<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsMoney;
use App\Models\QuoteItem;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin QuoteItem */
class QuoteItemResource extends JsonResource
{
    use FormatsMoney;

    public function toArray($request): array
    {
        $currency = $this->quote?->currency ?? config('billing.currency');

        return [
            'id' => $this->id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => $this->money($this->unit_price, $currency),
            'total' => $this->money($this->total, $currency),
            'taxable' => $this->taxable,
            'product_id' => $this->product_id,
            'billing_cycle' => $this->billing_cycle?->value,
            'label' => $this->label,
        ];
    }
}
