<?php

namespace App\Http\Resources;

use App\Models\WebhookDelivery;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WebhookDelivery */
class WebhookDeliveryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'webhook_id' => $this->webhook_endpoint_id,
            'event' => $this->event,
            'payload' => $this->payload,
            'status_code' => $this->status_code,
            'success' => $this->success,
            'attempts' => $this->attempts,
            'response' => $this->response,
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
