<?php

namespace App\Http\Resources;

use App\Models\AutopayAttempt;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One automatic payment attempt. The list query joins the invoice and the payment method, which is where client_id,
 * invoice_number, invoice_uuid and payment_method_uuid come from. @mixin AutopayAttempt
 */
class AutopayAttemptResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'invoice_uuid' => $this->invoice_uuid,
            'invoice_number' => $this->invoice_number,
            'client_id' => $this->client_id,
            'payment_method_uuid' => $this->payment_method_uuid,
            'attempt' => $this->attempt,
            'status' => $this->status,
            'gateway' => $this->gateway,
            'reference' => $this->reference,
            'message' => $this->message,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
