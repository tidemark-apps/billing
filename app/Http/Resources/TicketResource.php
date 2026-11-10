<?php

namespace App\Http\Resources;

use App\Models\Ticket;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ticket
 * Customer view: identified by uuid, only their own reply thread, only customer-visible custom fields, no internal ids.
 * Staff view: ids, requester, assignee, source, every custom field; `messages` includes internal notes and system lines.
 */
class TicketResource extends JsonResource
{
    public function toArray($request): array
    {
        $staff = (bool) $request?->user()?->isStaff();

        return [
            'id' => $this->when($staff, $this->id),
            'uuid' => $this->uuid,
            'number' => $this->number,
            'subject' => $this->subject,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'priority' => $this->priority->value,
            'is_locked' => $this->is_locked,
            'can_reply' => $this->when(! $staff, ! $this->is_locked),
            'department' => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name, 'slug' => $this->department->slug] : null,
            'service_uuid' => $this->service?->uuid,
            'client_id' => $this->when($staff, $this->client_id),
            'user_id' => $this->when($staff, $this->user_id),
            'service_id' => $this->when($staff, $this->service_id),
            'requester_name' => $this->when($staff, $this->requester_name),
            'requester_email' => $this->when($staff, $this->requester_email),
            'assigned_to' => $this->when($staff, $this->assigned_to),
            'assignee' => $this->when($staff, fn () => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null),
            'source' => $this->when($staff, $this->source),
            'custom_fields' => $this->customFields($request, $staff),
            'last_reply_at' => $this->last_reply_at?->toIso8601String(),
            'last_reply_by' => $this->last_reply_by,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'messages' => TicketMessageResource::collection($this->whenLoaded('messages')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** Staff-only fields are filtered out for customers, using the department's current field definitions. */
    private function customFields($request, bool $staff): object
    {
        $values = (array) ($this->custom_fields ?? []);
        if (! $staff) {
            $cache = $request->attributes->get('ticket_visible_fields', []);
            $key = (int) $this->department_id;
            if (! isset($cache[$key])) {
                $cache[$key] = $this->department ? $this->department->applicableFields(forCustomer: true)->pluck('key')->all() : [];
                $request->attributes->set('ticket_visible_fields', $cache);
            }
            $values = array_intersect_key($values, array_flip($cache[$key]));
        }

        return (object) $values;
    }
}
