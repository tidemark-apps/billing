<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsMoney;
use App\Models\Invoice;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    use FormatsMoney;

    public function toArray($request): array
    {
        $c = $this->currency;

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'number' => $this->number,
            'status' => $this->status->value,
            'client_id' => $this->client_id,
            'quote_id' => $this->quote_id,
            'currency' => $c,
            'subtotal' => $this->money($this->subtotal, $c),
            'tax_total' => $this->money($this->tax_total, $c),
            'total' => $this->money($this->total, $c),
            'amount_paid' => $this->money($this->amount_paid, $c),
            'balance' => $this->money($this->balance(), $c),
            'tax_name' => $this->tax_name,
            'tax_rate' => $this->tax_rate,
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'is_overdue' => $this->isOverdue(),
            'reference' => $this->reference,
            'notes' => $this->notes,
            'terms' => $this->terms,
            'pay_url' => $this->number ? route('invoice.public', $this->uuid) : null,
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'client' => new ClientResource($this->whenLoaded('client')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
