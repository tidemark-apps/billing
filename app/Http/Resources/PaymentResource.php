<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsMoney;
use App\Models\Payment;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    use FormatsMoney;

    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'invoice_id' => $this->invoice_id,
            'client_id' => $this->client_id,
            'type' => $this->type,
            'status' => $this->status->value,
            'gateway' => $this->gateway,
            'reference' => $this->reference,
            'amount' => $this->money($this->amount, $this->currency),
            'fee' => $this->money($this->fee, $this->currency),
            'refund_of_id' => $this->refund_of_id,
            'notes' => $this->when($this->isStaffRequest($request), $this->notes),
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
