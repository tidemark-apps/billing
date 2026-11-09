<?php

namespace Fixtures\Demo;

use App\Models\Service;
use App\Plugins\AbstractModule;
use App\Support\ProvisionResult;

class DemoModule extends AbstractModule
{
    public function key(): string
    {
        return 'demo';
    }

    public function label(): string
    {
        return 'Demo module';
    }

    public function create(Service $service): ProvisionResult
    {
        return ProvisionResult::ok('ok', ['demo' => true]);
    }
}
