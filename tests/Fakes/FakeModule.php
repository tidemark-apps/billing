<?php

namespace Tests\Fakes;

use App\Models\Service;
use App\Plugins\AbstractModule;
use App\Support\ProvisionResult;

class FakeModule extends AbstractModule
{
    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake module';
    }

    public function create(Service $service): ProvisionResult
    {
        return ProvisionResult::ok('created', ['username' => 'fake-account'], ['password' => 's3cret']);
    }
}
