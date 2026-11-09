<?php

namespace App\Http\Resources;

use App\Models\ServiceLog;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceLog */
class ServiceLogResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'service_id' => $this->service_id,
            'action' => $this->action,
            'success' => $this->success,
            'message' => $this->message,
            'user' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
