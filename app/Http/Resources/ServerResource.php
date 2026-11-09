<?php

namespace App\Http\Resources;

use App\Models\Server;
use App\Plugins\ModuleRegistry;
use App\Support\Fields;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Server  The connection secret is write-only: it is never returned, only `has_secret`. Module config is returned
 * without its password-type fields (`config_secrets_set` says which are stored); if the module's plugin is not enabled
 * we cannot tell which keys are secret, so no config is returned at all.
 */
class ServerResource extends JsonResource
{
    public function toArray($request): array
    {
        $fields = app(ModuleRegistry::class)->get($this->module)?->serverFields();
        $secretKeys = $fields === null ? [] : Fields::secretKeys($fields);
        $config = (array) ($this->config ?? []);

        return [
            'id' => $this->id,
            'module' => $this->module,
            'name' => $this->name,
            'hostname' => $this->hostname,
            'port' => $this->port,
            'username' => $this->username,
            'use_ssl' => $this->use_ssl,
            'verify_ssl' => $this->verify_ssl,
            'is_active' => $this->is_active,
            'max_services' => $this->max_services,
            'notes' => $this->notes,
            'has_secret' => ! empty($this->secret),
            'config' => $fields === null ? new \stdClass : (object) array_diff_key($config, array_flip($secretKeys)),
            'config_secrets_set' => $fields === null ? new \stdClass : (object) collect($secretKeys)->mapWithKeys(fn ($k) => [$k => ! empty($config[$k])])->all(),
            'live_services_count' => $this->when(isset($this->live_count), fn () => (int) $this->live_count),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
