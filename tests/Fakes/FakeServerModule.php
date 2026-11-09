<?php

namespace Tests\Fakes;

use App\Models\Server;
use App\Models\Service;
use App\Plugins\AbstractModule;
use App\Support\ProvisionResult;

class FakeServerModule extends AbstractModule
{
    public function key(): string
    {
        return 'fakeserver';
    }

    public function label(): string
    {
        return 'Fake server module';
    }

    public function usesServers(): bool
    {
        return true;
    }

    public function serverFields(): array
    {
        return [['key' => 'reseller', 'label' => 'Reseller', 'type' => 'text'], ['key' => 'token', 'label' => 'Token', 'type' => 'password']];
    }

    public function productFields(): array
    {
        return [['key' => 'package', 'label' => 'Package', 'type' => 'text', 'required' => true]];
    }

    public function testServer(Server $server): ProvisionResult
    {
        return $server->hostname === 'good.example.com' ? ProvisionResult::ok('v1.2.3') : ProvisionResult::fail('refused');
    }

    public function create(Service $service): ProvisionResult
    {
        return ProvisionResult::ok('created', ['server' => $service->server?->hostname]);
    }

    public function adminActions(): array
    {
        return ['ping' => 'Ping'];
    }

    public function runAction(Service $service, string $action, string $area): ProvisionResult
    {
        return ProvisionResult::ok('pong');
    }
}
