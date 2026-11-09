<?php

namespace App\Http\Resources;

use App\Models\ActivityLog;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin ActivityLog */
class ActivityLogResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'description' => $this->description,
            'subject_type' => $this->subject_type ? Str::snake(class_basename($this->subject_type)) : null,
            'subject_id' => $this->subject_id,
            'properties' => $this->properties ?: new \stdClass,
            'ip' => $this->ip,
            'user' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
