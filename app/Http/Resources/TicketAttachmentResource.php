<?php

namespace App\Http\Resources;

use App\Models\TicketAttachment;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TicketAttachment  File metadata and the download URL; the storage disk and path are never exposed. */
class TicketAttachmentResource extends JsonResource
{
    public function toArray($request): array
    {
        $staff = (bool) $request?->user()?->isStaff();
        $ticket = $this->ticket;

        return [
            'uuid' => $this->uuid,
            'filename' => $this->original_name,
            'mime' => $this->mime,
            'size' => (int) $this->size,
            'sha256' => $this->sha256,
            'download_url' => $staff
                ? route('api.admin.tickets.attachments.download', [$this->ticket_id, $this->uuid])
                : route('api.client.tickets.attachments.download', [$ticket->uuid, $this->uuid]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
