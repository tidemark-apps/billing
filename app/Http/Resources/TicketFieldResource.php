<?php

namespace App\Http\Resources;

use App\Models\TicketField;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TicketField */
class TicketFieldResource extends JsonResource
{
    public function toArray($request): array
    {
        $staff = (bool) $request?->user()?->isStaff();

        return [
            'id' => $this->id,
            'department_id' => $this->when($staff, $this->department_id),
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'options' => $this->options ?? [],
            'required' => $this->required,
            'staff_only' => $this->when($staff, $this->staff_only),
            'help' => $this->help,
            'sort' => $this->sort,
            'is_active' => $this->when($staff, $this->is_active),
        ];
    }
}
