<?php

namespace Plugins\LicenseServer;

use App\Models\Service;
use App\Plugins\AbstractModule;
use App\Support\ProvisionResult;
use App\Support\Settings;
use Illuminate\Support\Str;
use Plugins\LicenseServer\Events\LicenseRevoked;
use Plugins\LicenseServer\Models\License;

class LicenseModule extends AbstractModule
{
    public function key(): string
    {
        return 'license';
    }

    public function label(): string
    {
        return 'Software licence';
    }

    public function description(): string
    {
        return 'Issues a licence key that your software verifies against this server. Valid while the service is active.';
    }

    public function productFields(): array
    {
        return [
            ['key' => 'product_code', 'label' => 'Product code', 'type' => 'text', 'help' => 'Sent by client software as "product". Defaults to the product slug.'],
            ['key' => 'key_prefix', 'label' => 'Key prefix', 'type' => 'text', 'placeholder' => 'ACME', 'help' => 'Up to 10 letters/digits.'],
            ['key' => 'max_activations', 'label' => 'Max activations', 'type' => 'number', 'default' => 1],
            ['key' => 'binding', 'label' => 'Bind activations to', 'type' => 'select', 'options' => ['domain' => 'Domain', 'ip' => 'IP address', 'instance' => 'Instance id', 'none' => 'Nothing (key only)'], 'default' => 'domain'],
            ['key' => 'grace_days', 'label' => 'Grace days after suspension', 'type' => 'number', 'help' => 'Blank uses the plugin default.'],
        ];
    }

    public function create(Service $service): ProvisionResult
    {
        $existing = License::where('service_id', $service->id)->first();
        if ($existing) {
            return ProvisionResult::ok('Licence already issued.', ['license_key' => $existing->key]);
        }
        $license = License::create(['service_id' => $service->id, 'key' => KeyGenerator::generate($service->product?->moduleOption('key_prefix')), 'status' => 'active'] + $this->settingsFor($service));

        return ProvisionResult::ok('Licence issued.', ['license_key' => $license->key]);
    }

    public function suspend(Service $service, ?string $reason = null): ProvisionResult
    {
        return $this->change($service, fn (License $l) => $l->status === 'active' ? $l->update(['status' => 'suspended', 'suspended_at' => now()]) : null, 'Licence suspended.');
    }

    public function unsuspend(Service $service): ProvisionResult
    {
        return $this->change($service, fn (License $l) => $l->status === 'suspended' ? $l->update(['status' => 'active', 'suspended_at' => null]) : null, 'Licence reactivated.');
    }

    public function terminate(Service $service): ProvisionResult
    {
        return $this->change($service, function (License $l) {
            if ($l->status !== 'revoked') {
                $l->update(['status' => 'revoked']);
                event(new LicenseRevoked($l, 'service terminated'));
            }
        }, 'Licence revoked.');
    }

    public function changePackage(Service $service): ProvisionResult
    {
        return $this->change($service, fn (License $l) => $l->update($this->settingsFor($service)), 'Licence limits updated.');
    }

    /** @return array<string, mixed> */
    private function settingsFor(Service $service): array
    {
        $p = $service->product;
        $binding = $p?->moduleOption('binding') ?: 'domain';
        $grace = $p?->moduleOption('grace_days');

        return [
            'product_code' => (string) ($p?->moduleOption('product_code') ?: ($p?->slug ?? Str::slug($p?->name ?? 'product'))),
            'max_activations' => max(1, (int) ($p?->moduleOption('max_activations') ?: 1)),
            'binding' => in_array($binding, ['domain', 'ip', 'instance', 'none'], true) ? $binding : 'domain',
            'grace_days' => max(0, (int) (($grace === null || $grace === '') ? Settings::get('plugin.license-server.default_grace_days', 0) : $grace)),
        ];
    }

    private function change(Service $service, \Closure $apply, string $message): ProvisionResult
    {
        $license = License::where('service_id', $service->id)->first();
        if (! $license) {
            return ProvisionResult::fail('No licence exists for this service.');
        }
        $apply($license);

        return ProvisionResult::ok($message);
    }
}
