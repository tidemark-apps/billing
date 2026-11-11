<?php

namespace App\Plugins;

use App\Contracts\ProvisioningModule;
use App\Models\Server;
use App\Models\Service;
use App\Support\ProvisionResult;
use App\Support\Settings;

/** Convenience base: everything except create() has a harmless default, so a module only implements what it supports. */
abstract class AbstractModule implements ProvisioningModule
{
    public function description(): string
    {
        return '';
    }

    public function usesServers(): bool
    {
        return false;
    }

    public function serverFields(): array
    {
        return [];
    }

    public function productFields(): array
    {
        return [];
    }

    public function testServer(Server $server): ProvisionResult
    {
        return ProvisionResult::fail('This module cannot test a connection.');
    }

    public function suspend(Service $service, ?string $reason = null): ProvisionResult
    {
        return ProvisionResult::ok('Nothing to do for this module.');
    }

    public function unsuspend(Service $service): ProvisionResult
    {
        return ProvisionResult::ok('Nothing to do for this module.');
    }

    public function terminate(Service $service): ProvisionResult
    {
        return ProvisionResult::ok('Nothing to do for this module.');
    }

    public function changePackage(Service $service): ProvisionResult
    {
        return ProvisionResult::ok('Nothing to do for this module.');
    }

    public function adminActions(): array
    {
        return [];
    }

    public function clientActions(): array
    {
        return [];
    }

    /** The customer's buttons for one service; override when they depend on the service. Defaults to {@see clientActions()}. @return array<string, string> */
    public function clientActionsFor(Service $service): array
    {
        return $this->clientActions();
    }

    /**
     * What the customer sees in the Details card of their service page, as label => value. Return null (the default) to show every
     * simple value the module stored; return an array to show only what a customer needs. A value that starts with https:// is a link.
     *
     * @return array<string, string>|null
     */
    public function clientDetails(Service $service): ?array
    {
        return null;
    }

    public function runAction(Service $service, string $action, string $area): ProvisionResult
    {
        return ProvisionResult::fail("Unknown action \"$action\".");
    }

    /** A module-wide setting (Settings::get("module.{key}.{name}")). */
    protected function setting(string $name, mixed $default = null): mixed
    {
        return Settings::get('module.'.$this->key().'.'.$name, $default);
    }

    /** A random password that satisfies common hosting panel policies. */
    protected function generatePassword(int $length = 20): string
    {
        $sets = ['abcdefghijkmnopqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '!@#%^*-_'];
        $pw = '';
        foreach ($sets as $set) {
            $pw .= $set[random_int(0, strlen($set) - 1)];
        }
        $all = implode('', $sets);
        while (strlen($pw) < $length) {
            $pw .= $all[random_int(0, strlen($all) - 1)];
        }

        return str_shuffle($pw);
    }
}
