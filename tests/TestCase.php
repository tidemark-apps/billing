<?php

namespace Tests;

use App\Models\User;
use App\Plugins\PluginManager;
use App\Support\Settings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Settings::flush();
        // Never render test PDFs against fonts installed on the developer's machine (CJK packs are tens of MB).
        config(['pdf-fonts.work_dir' => sys_get_temp_dir().'/billing-test-dompdf']);
    }

    /** Enable a plugin from plugins/ (running its migrations) and load it into this test's app. */
    protected function enablePlugin(string $slug): void
    {
        $manager = app(PluginManager::class);
        $manager->enable($slug);
        $manager->bootNow($slug);
    }

    protected function admin(): User
    {
        return User::factory()->admin()->create();
    }

    protected function customer(array $client = []): User
    {
        return User::factory()->withClient($client)->create();
    }
}
