<?php

namespace App\Http\Resources;

use App\Models\TicketMessage;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TicketMessage  Customers only ever get `reply` messages (the controllers filter), without staff e-mail addresses or user ids. */
class TicketMessageResource extends JsonResource
{
    public function toArray($request): array
    {
        $staff = (bool) $request?->user()?->isStaff();

        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'author_type' => $this->author_type,
            'author_name' => $this->author_name,
            'author_email' => $this->when($staff, $this->author_email),
            'user_id' => $this->when($staff, $this->user_id),
            'source' => $this->when($staff, $this->source),
            'body' => $this->body,
            'attachments' => TicketAttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
