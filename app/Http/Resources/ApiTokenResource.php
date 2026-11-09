<?php

namespace App\Http\Resources;

use App\Models\ApiToken;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApiToken  Never includes the token itself; the plain value is only returned when it is created. */
class ApiTokenResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'prefix' => $this->prefix,
            'user_id' => $this->user_id,
            'abilities' => $this->abilities,
            'full_access' => $this->abilities === null,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_expired' => $this->isExpired(),
            'is_current' => (bool) ($request?->attributes->get('api_token')?->id === $this->id),
            'user' => $this->whenLoaded('user', fn () => ['id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email, 'role' => $this->user->role->value]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
