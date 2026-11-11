<?php

namespace Plugins\CPanel;

use App\Models\Server;
use App\Models\Service;
use App\Plugins\AbstractModule;
use App\Support\ProvisionResult;

class CPanelModule extends AbstractModule
{
    private const MAX_USERNAME_ATTEMPTS = 6;

    public function key(): string
    {
        return 'cpanel';
    }

    public function label(): string
    {
        return 'cPanel & WHM';
    }

    public function description(): string
    {
        return 'Creates and manages cPanel hosting accounts through the WHM API.';
    }

    public function usesServers(): bool
    {
        return true;
    }

    public function serverFields(): array
    {
        return [
            ['key' => 'nameserver1', 'label' => 'Nameserver 1', 'type' => 'text', 'placeholder' => 'ns1.example.com', 'help' => 'Shown to customers on their service page.'],
            ['key' => 'nameserver2', 'label' => 'Nameserver 2', 'type' => 'text', 'placeholder' => 'ns2.example.com'],
        ];
    }

    public function productFields(): array
    {
        return [
            ['key' => 'package', 'label' => 'WHM package', 'type' => 'text', 'required' => true, 'help' => 'The exact hosting package name in WHM (Packages → List Packages). Reseller-owned packages are prefixed with the reseller name.'],
            ['key' => 'feature_list', 'label' => 'Feature list', 'type' => 'text', 'help' => 'Optional. Overrides the package\'s feature list.'],
            ['key' => 'dedicated_ip', 'label' => 'IP address', 'type' => 'select', 'options' => ['n' => 'Shared', 'y' => 'Dedicated'], 'default' => 'n'],
        ];
    }

    public function testServer(Server $server): ProvisionResult
    {
        try {
            $client = new WhmClient($server);
            $version = $client->call('version')['data']['version'] ?? 'unknown version';
            $packages = $client->call('listpkgs', ['want' => 'creatable'])['data']['pkg'] ?? [];

            return ProvisionResult::ok('Connected to cPanel & WHM '.$version.'. '.count($packages).' package(s) available.');
        } catch (WhmException $e) {
            return ProvisionResult::fail($e->getMessage());
        }
    }

    public function create(Service $service): ProvisionResult
    {
        $server = $service->server;
        $package = (string) $service->product?->moduleOption('package');
        if (! $server) {
            return ProvisionResult::fail('No server is assigned to this service.');
        }
        if ($package === '') {
            return ProvisionResult::fail('The product has no WHM package set.');
        }
        $domain = $this->normalizeDomain((string) $service->label);
        if ($domain === null) {
            return ProvisionResult::fail('A valid domain name is required (the service label is "'.$service->label.'").');
        }

        $client = new WhmClient($server);
        $password = $this->generatePassword();
        $username = (string) $service->data('username') ?: null;

        for ($attempt = 0; $attempt < self::MAX_USERNAME_ATTEMPTS; $attempt++) {
            $candidate = $username ?? UsernameGenerator::make($domain, $attempt);
            $params = array_filter([
                'username' => $candidate,
                'domain' => $domain,
                'plan' => $package,
                'password' => $password,
                'contactemail' => $service->client->email,
                'featurelist' => $service->product->moduleOption('feature_list'),
                'ip' => $service->product->moduleOption('dedicated_ip', 'n') === 'y' ? 'y' : 'n',
            ], fn ($v) => $v !== null && $v !== '');

            try {
                $client->call('createacct', $params);
            } catch (WhmException $e) {
                if ($username === null && $this->isUsernameTaken($e->getMessage())) {
                    continue;
                }

                return ProvisionResult::fail($e->getMessage());
            }

            return ProvisionResult::ok('Account '.$candidate.' created on '.$server->hostname.'.', array_filter([
                'username' => $candidate,
                'domain' => $domain,
                'package' => $package,
                'server' => $server->hostname,
                'nameserver1' => $server->option('nameserver1'),
                'nameserver2' => $server->option('nameserver2'),
            ]), ['password' => $password]);
        }

        return ProvisionResult::fail('Could not find a free username for '.$domain.' after '.self::MAX_USERNAME_ATTEMPTS.' tries.');
    }

    public function suspend(Service $service, ?string $reason = null): ProvisionResult
    {
        return $this->simple($service, 'suspendacct', ['user' => $service->data('username'), 'reason' => $reason ?: 'Suspended by billing system'], 'Account suspended.');
    }

    public function unsuspend(Service $service): ProvisionResult
    {
        return $this->simple($service, 'unsuspendacct', ['user' => $service->data('username')], 'Account unsuspended.');
    }

    public function terminate(Service $service): ProvisionResult
    {
        return $this->simple($service, 'removeacct', ['username' => $service->data('username'), 'keepdns' => 0], 'Account removed.');
    }

    public function changePackage(Service $service): ProvisionResult
    {
        $package = (string) $service->product?->moduleOption('package');
        $result = $this->simple($service, 'changepackage', ['user' => $service->data('username'), 'pkg' => $package], 'Package changed to '.$package.'.');

        return $result->success ? ProvisionResult::ok($result->message, ['package' => $package]) : $result;
    }

    public function adminActions(): array
    {
        return ['login' => 'Log in to cPanel', 'reset_password' => 'Reset password'];
    }

    public function clientActions(): array
    {
        return ['login' => 'Log in to cPanel', 'webmail' => 'Open webmail', 'reset_password' => 'Reset password'];
    }

    public function clientDetails(Service $service): ?array
    {
        $ns = array_filter([$service->data('nameserver1'), $service->data('nameserver2')]);

        return array_filter([
            'Domain' => $service->data('domain'),
            'Username' => $service->data('username'),
            'Server' => $service->data('server'),
            'Nameservers' => $ns ? implode(', ', $ns) : null,
        ]);
    }

    public function runAction(Service $service, string $action, string $area): ProvisionResult
    {
        return match ($action) {
            'login' => $this->session($service, 'cpaneld'),
            'webmail' => $this->session($service, 'webmaild'),
            'reset_password' => $this->resetPassword($service),
            default => ProvisionResult::fail("Unknown action \"$action\"."),
        };
    }

    private function session(Service $service, string $panelService): ProvisionResult
    {
        $result = $this->simple($service, 'create_user_session', ['user' => $service->data('username'), 'service' => $panelService], '', fn (array $data) => $data);
        if (! $result->success) {
            return $result;
        }
        $url = $result->data['url'] ?? null;

        return $url ? ProvisionResult::redirect($url) : ProvisionResult::fail('WHM did not return a login URL.');
    }

    private function resetPassword(Service $service): ProvisionResult
    {
        $password = $this->generatePassword();
        $result = $this->simple($service, 'passwd', ['user' => $service->data('username'), 'password' => $password], 'Password reset. The new password is on the service page.');

        return $result->success ? ProvisionResult::ok($result->message, [], ['password' => $password]) : $result;
    }

    /**
     * Run one WHM call for the service's account.
     *
     * @param  array<string, scalar|null>  $params
     * @param  (callable(array<string, mixed>): array<string, mixed>)|null  $data  maps the WHM response data into the result's data
     */
    private function simple(Service $service, string $function, array $params, string $message, ?callable $data = null): ProvisionResult
    {
        if (! $service->server) {
            return ProvisionResult::fail('No server is assigned to this service.');
        }
        if (! $service->data('username')) {
            return ProvisionResult::fail('This service has no cPanel username yet.');
        }
        try {
            $response = (new WhmClient($service->server))->call($function, array_filter($params, fn ($v) => $v !== null));
        } catch (WhmException $e) {
            return ProvisionResult::fail($e->getMessage());
        }

        return ProvisionResult::ok($message, $data ? $data($response['data']) : []);
    }

    private function isUsernameTaken(string $message): bool
    {
        return (bool) preg_match('/user(name)?\b.*(exist|taken|in use|already|reserved|not available)|(exist|taken|in use|already).*user(name)?\b/i', $message)
            && ! preg_match('/domain/i', $message);
    }

    private function normalizeDomain(string $label): ?string
    {
        $domain = strtolower(trim(preg_replace('~^https?://~i', '', trim($label)), '/ '));

        return preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) ? $domain : null;
    }
}
