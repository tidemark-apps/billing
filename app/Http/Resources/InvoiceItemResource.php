<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsMoney;
use App\Models\InvoiceItem;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InvoiceItem */
class InvoiceItemResource extends JsonResource
{
    use FormatsMoney;

    public function toArray($request): array
    {
        $currency = $this->invoice?->currency ?? config('billing.currency');

        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => $this->money($this->unit_price, $currency),
            'total' => $this->money($this->total, $currency),
            'taxable' => $this->taxable,
            'service_id' => $this->service_id,
            'product_id' => $this->product_id,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
        ];
    }
}
