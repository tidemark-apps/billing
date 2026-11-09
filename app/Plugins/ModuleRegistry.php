<?php

namespace App\Plugins;

use App\Contracts\ProvisioningModule;

class ModuleRegistry
{
    /** @var array<string, ProvisioningModule> */
    private array $modules = [];

    public function register(ProvisioningModule|string $module): void
    {
        $module = is_string($module) ? app($module) : $module;
        $this->modules[$module->key()] = $module;
    }

    /** @return array<string, ProvisioningModule> */
    public function all(): array
    {
        return $this->modules;
    }

    public function get(?string $key): ?ProvisioningModule
    {
        return $key ? ($this->modules[$key] ?? null) : null;
    }

    /** @return array<string, string> key => label, for select inputs */
    public function options(): array
    {
        return array_map(fn (ProvisioningModule $m) => $m->label(), $this->modules);
    }
}
