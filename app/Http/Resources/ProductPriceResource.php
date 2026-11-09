<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsMoney;
use App\Models\ProductPrice;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductPrice */
class ProductPriceResource extends JsonResource
{
    use FormatsMoney;

    public function toArray($request): array
    {
        return [
            'cycle' => $this->cycle->value,
            'cycle_label' => $this->cycle->label(),
            'currency' => $this->currency,
            'amount' => $this->money($this->amount, $this->currency),
            'setup_fee' => $this->money($this->setup_fee, $this->currency),
        ];
    }
}
