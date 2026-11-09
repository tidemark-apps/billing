<?php

namespace App\Http\Resources;

use App\Models\PaymentMethod;
use Illuminate\Http\Resources\Json\JsonResource;

/** A saved payment method. Only display details: the gateway token and its data are never part of the API. @mixin PaymentMethod */
class PaymentMethodResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid' => $this->uuid,
            'client_id' => $this->client_id,
            'gateway' => $this->gateway,
            'brand' => $this->brand,
            'last4' => $this->last4,
            'exp_month' => $this->exp_month,
            'exp_year' => $this->exp_year,
            'expiry' => $this->expiry(),
            'is_expired' => $this->isExpired(),
            'label' => $this->label,
            'display_name' => $this->displayName(),
            'is_default' => $this->is_default,
            'autopay' => $this->autopay,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
