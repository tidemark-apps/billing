<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsMoney;
use App\Models\Quote;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Quote */
class QuoteResource extends JsonResource
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
            'invoice_id' => $this->invoice_id,
            'title' => $this->title,
            'currency' => $c,
            'subtotal' => $this->money($this->subtotal, $c),
            'tax_total' => $this->money($this->tax_total, $c),
            'total' => $this->money($this->total, $c),
            'tax_name' => $this->tax_name,
            'tax_rate' => $this->tax_rate,
            'issue_date' => $this->issue_date?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'notes' => $this->notes,
            'terms' => $this->terms,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'declined_at' => $this->declined_at?->toIso8601String(),
            'accepted_by' => $this->accepted_by,
            'view_url' => $this->number ? route('quote.public', $this->uuid) : null,
            'items' => QuoteItemResource::collection($this->whenLoaded('items')),
            'invoice' => new InvoiceResource($this->whenLoaded('invoice')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
