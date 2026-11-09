<?php

namespace App\Contracts;

use App\Models\Server;
use App\Models\Service;
use App\Support\Fields;
use App\Support\ProvisionResult;

/**
 * A provisioning module turns a paid service into something real: a hosting account, a license key, a VM.
 * Plugins register one with $this->registerModule(). Most modules should extend App\Plugins\AbstractModule.
 *
 * Field definitions ({@see Fields}) describe extra inputs for servers and products.
 */
interface ProvisioningModule
{
    /** Stable identifier stored on products, e.g. "cpanel". */
    public function key(): string;

    public function label(): string;

    public function description(): string;

    /** Whether services of this module live on a server record (control panels do, license keys do not). */
    public function usesServers(): bool;

    /** @return array<int, array<string, mixed>> extra fields on the server form (stored in servers.config) */
    public function serverFields(): array;

    /** @return array<int, array<string, mixed>> per-product options such as a hosting package (stored in products.module_config) */
    public function productFields(): array;

    public function testServer(Server $server): ProvisionResult;

    /** Create the account. Return data (shown to staff and client) and secrets (encrypted, e.g. a generated password). */
    public function create(Service $service): ProvisionResult;

    public function suspend(Service $service, ?string $reason = null): ProvisionResult;

    public function unsuspend(Service $service): ProvisionResult;

    public function terminate(Service $service): ProvisionResult;

    /** The product or billing details changed; sync the remote account. */
    public function changePackage(Service $service): ProvisionResult;

    /** @return array<string, string> custom buttons for staff on the service page: action => label */
    public function adminActions(): array;

    /** @return array<string, string> custom buttons for the customer on their service page: action => label */
    public function clientActions(): array;

    /** @param  string  $area  "admin" or "portal" */
    public function runAction(Service $service, string $action, string $area): ProvisionResult;
}
