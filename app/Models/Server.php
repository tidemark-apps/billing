<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'config' => 'encrypted:array',
            'use_ssl' => 'boolean',
            'verify_ssl' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function hasCapacity(): bool
    {
        return $this->max_services === null
            || $this->services()->whereIn('status', ['active', 'suspended', 'pending'])->count() < $this->max_services;
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    public function baseUrl(?int $defaultPort = null): string
    {
        $port = $this->port ?: $defaultPort;
        $host = $this->hostname;

        return ($this->use_ssl ? 'https://' : 'http://').$host.($port ? ':'.$port : '');
    }
}
