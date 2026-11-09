<?php

namespace App\Http\Resources;

use App\Models\TaxRate;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxRate */
class TaxRateResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'rate' => $this->rate, 'country' => $this->country, 'region' => $this->region, 'is_active' => $this->is_active];
    }
}
