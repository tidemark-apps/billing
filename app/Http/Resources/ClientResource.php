<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsMoney;
use App\Models\Client;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Client */
class ClientResource extends JsonResource
{
    use FormatsMoney;

    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'company_name' => $this->company_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => [
                'line1' => $this->address_1, 'line2' => $this->address_2, 'city' => $this->city,
                'state' => $this->state, 'postcode' => $this->postcode, 'country' => $this->country,
            ],
            'tax_id' => $this->tax_id,
            'currency' => $this->currency,
            'status' => $this->status,
            'is_internal' => (bool) $this->is_internal,
            'notes' => $this->when($this->isStaffRequest($request), $this->notes),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
