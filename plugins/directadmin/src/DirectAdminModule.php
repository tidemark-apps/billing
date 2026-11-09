<?php

namespace Plugins\DirectAdmin;

use App\Models\Server;
use App\Models\Service;
use App\Plugins\AbstractModule;
use App\Support\ProvisionResult;

class DirectAdminModule extends AbstractModule
{
    private const MAX_USERNAME_ATTEMPTS = 6;

    public function key(): string
    {
        return 'directadmin';
    }

    public function label(): string
    {
        return 'DirectAdmin';
    }

    public function description(): string
    {
        return 'Creates and manages DirectAdmin hosting accounts through a reseller (or admin) login.';
    }

    public function usesServers(): bool
    {
        return true;
    }

    public function serverFields(): array
    {
        return [
            ['key' => 'ip', 'label' => 'Shared IP address', 'type' => 'text', 'help' => 'IP new shared accounts are created on. Leave blank to use the first shared or server IP the reseller owns.'],
            ['key' => 'max_username_length', 'label' => 'Max username length', 'type' => 'text', 'placeholder' => '10', 'help' => 'Match max_username_length in directadmin.conf (DirectAdmin default 10, allowed 8 to 14). Leave blank for 10.'],
            ['key' => 'nameserver1', 'label' => 'Nameserver 1', 'type' => 'text', 'placeholder' => 'ns1.example.com', 'help' => 'Shown to customers on their service page.'],
            ['key' => 'nameserver2', 'label' => 'Nameserver 2', 'type' => 'text', 'placeholder' => 'ns2.example.com'],
        ];
    }

    public function productFields(): array
    {
        return [
            ['key' => 'package', 'label' => 'User package', 'type' => 'text', 'required' => true, 'help' => 'The exact name of a user package owned by the reseller (Reseller Level → Manage User Packages).'],
            ['key' => 'ip_mode', 'label' => 'IP address', 'type' => 'select', 'options' => ['shared' => 'Shared', 'dedicated' => 'Dedicated (next free IP)'], 'default' => 'shared'],
        ];
    }

    public function testServer(Server $server): ProvisionResult
    {
        try {
            $reply = (new DirectAdminClient($server))->command('CMD_API_PACKAGES_USER', [], post: false);
            $count = count((array) ($reply['list'] ?? []));
            $ips = count((array) ((new DirectAdminClient($server))->command('CMD_API_SHOW_RESELLER_IPS', [], post: false)['list'] ?? []));

            return ProvisionResult::ok("Connected to DirectAdmin as {$server->username}. $count user package(s), $ips IP(s).");
        } catch (DirectAdminException $e) {
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
            return ProvisionResult::fail('The product has no DirectAdmin package set.');
        }
        $domain = $this->normalizeDomain((string) $service->label);
        if ($domain === null) {
            return ProvisionResult::fail('A valid domain name is required (the service label is "'.$service->label.'").');
        }

        $client = new DirectAdminClient($server);
        try {
            $ip = $this->pickIp($client, $server, (string) $service->product->moduleOption('ip_mode', 'shared'));
        } catch (DirectAdminException $e) {
            return ProvisionResult::fail($e->getMessage());
        }
        $password = $this->generatePassword();
        $fixed = (string) $service->data('username') ?: null;

        for ($attempt = 0; $attempt < self::MAX_USERNAME_ATTEMPTS; $attempt++) {
            $username = $fixed ?? UsernameGenerator::make($domain, $attempt, $this->maxUsernameLength($server));
            try {
                $client->command('CMD_API_ACCOUNT_USER', array_filter([
                    'action' => 'create',
                    'add' => 'Submit',
                    'username' => $username,
                    'email' => $service->client->email,
                    'passwd' => $password,
                    'passwd2' => $password,
                    'domain' => $domain,
                    'package' => $package,
                    'ip' => $ip,
                    'notify' => 'no',
                ], fn ($v) => $v !== null && $v !== ''));
            } catch (DirectAdminException $e) {
                if ($fixed === null && $this->isUsernameTaken($e->getMessage())) {
                    continue;
                }

                return ProvisionResult::fail($e->getMessage());
            }

            return ProvisionResult::ok('Account '.$username.' created on '.$server->hostname.'.', array_filter([
                'username' => $username,
                'domain' => $domain,
                'package' => $package,
                'server' => $server->hostname,
                'ip' => $ip,
                'nameserver1' => $server->option('nameserver1'),
                'nameserver2' => $server->option('nameserver2'),
            ]), ['password' => $password]);
        }

        return ProvisionResult::fail('Could not find a free username for '.$domain.' after '.self::MAX_USERNAME_ATTEMPTS.' tries.');
    }

    /**
     * CMD_API_SELECT_USERS has a toggling "suspend=Suspend|Unsuspend" and, since DirectAdmin 1.31.0, definitive
     * "dosuspend" / "dounsuspend" (presence of the name triggers the action, the value is ignored). We use the definitive ones.
     */
    public function suspend(Service $service, ?string $reason = null): ProvisionResult
    {
        return $this->setSuspended($service, true);
    }

    public function unsuspend(Service $service): ProvisionResult
    {
        return $this->setSuspended($service, false);
    }

    public function terminate(Service $service): ProvisionResult
    {
        return $this->reseller($service, 'CMD_API_SELECT_USERS', ['confirmed' => 'Confirm', 'delete' => 'yes', 'select0' => $service->data('username')], 'Account removed.');
    }

    public function changePackage(Service $service): ProvisionResult
    {
        $package = (string) $service->product?->moduleOption('package');
        $result = $this->reseller($service, 'CMD_API_MODIFY_USER', ['action' => 'package', 'user' => $service->data('username'), 'package' => $package], 'Package changed to '.$package.'.');

        return $result->success ? ProvisionResult::ok($result->message, ['package' => $package]) : $result;
    }

    public function adminActions(): array
    {
        return ['login' => 'Log in to DirectAdmin', 'reset_password' => 'Reset password'];
    }

    public function clientActions(): array
    {
        return ['login' => 'Log in to DirectAdmin', 'reset_password' => 'Reset password'];
    }

    public function runAction(Service $service, string $action, string $area): ProvisionResult
    {
        return match ($action) {
            'login' => $this->login($service),
            'reset_password' => $this->resetPassword($service),
            default => ProvisionResult::fail("Unknown action \"$action\"."),
        };
    }

    /**
     * One-time login URL via CMD_API_LOGIN_KEYS (action=create&type=one_time_url), called as "reseller|user" (the documented
     * impersonation syntax: a reseller may impersonate the users it manages, no user password needed). DirectAdmin also asks
     * for the caller's current password in `passwd`, so this only works when the server secret is the reseller's password
     * (a Login Key is refused). On any failure we send the user to the panel's normal login page instead.
     */
    private function login(Service $service): ProvisionResult
    {
        $server = $service->server;
        $user = (string) $service->data('username');
        if (! $server || $user === '') {
            return ProvisionResult::fail('This service has no DirectAdmin account yet.');
        }
        $client = new DirectAdminClient($server);
        $secret = (string) $server->secret;
        try {
            $reply = $client->command('CMD_API_LOGIN_KEYS', [
                'action' => 'create',
                'type' => 'one_time_url',
                'expiry' => '5m',
                'redirect-url' => '/',
                'login_keys_notify_on_creation' => '0',
                'passwd' => $secret,
            ], asUser: $server->username.'|'.$user, asPassword: $secret);
            $url = (string) ($reply['details'] ?? '');
            if (preg_match('~^https?://~i', $url)) {
                return ProvisionResult::redirect($url);
            }
        } catch (DirectAdminException) {
            // fall through to the plain panel URL
        }

        return ProvisionResult::redirect($client->panelUrl(), 'Opening the DirectAdmin login page.');
    }

    /**
     * CMD_API_USER_PASSWD is POST-only. With the account's stored password we call it as the account itself and send the old
     * password; otherwise we call it as the reseller with only the target username and the new password (the form reported to
     * work for admins). Neither form is in the official reference; see README.
     */
    private function resetPassword(Service $service): ProvisionResult
    {
        $server = $service->server;
        $user = (string) $service->data('username');
        $old = (string) $service->secret('password');
        if (! $server || $user === '') {
            return ProvisionResult::fail('This service has no DirectAdmin account yet.');
        }
        $new = $this->generatePassword();
        $params = ['username' => $user, 'passwd' => $new, 'passwd2' => $new, 'password1' => $new, 'password2' => $new];
        try {
            $client = new DirectAdminClient($server);
            if ($old !== '') {
                $client->command('CMD_API_USER_PASSWD', $params + ['oldpassword' => $old], asUser: $user, asPassword: $old);
            } else {
                $client->command('CMD_API_USER_PASSWD', $params);
            }
        } catch (DirectAdminException $e) {
            return ProvisionResult::fail($e->getMessage());
        }

        return ProvisionResult::ok('Password reset. The new password is on the service page.', [], ['password' => $new]);
    }

    private function setSuspended(Service $service, bool $suspend): ProvisionResult
    {
        return $this->reseller($service, 'CMD_API_SELECT_USERS', [
            'location' => 'CMD_SELECT_USERS',
            $suspend ? 'dosuspend' : 'dounsuspend' => 'yes',
            'select0' => $service->data('username'),
        ], $suspend ? 'Account suspended.' : 'Account unsuspended.');
    }

    /** @param  array<string, scalar|null>  $params */
    private function reseller(Service $service, string $command, array $params, string $message): ProvisionResult
    {
        if (! $service->server) {
            return ProvisionResult::fail('No server is assigned to this service.');
        }
        if (! $service->data('username')) {
            return ProvisionResult::fail('This service has no DirectAdmin username yet.');
        }
        try {
            (new DirectAdminClient($service->server))->command($command, $params);
        } catch (DirectAdminException $e) {
            return ProvisionResult::fail($e->getMessage());
        }

        return ProvisionResult::ok($message);
    }

    /** Shared mode: the server's IP setting, else the reseller's first non-free IP. Dedicated: the first free one. */
    private function pickIp(DirectAdminClient $client, Server $server, string $mode): ?string
    {
        if ($mode !== 'dedicated') {
            if ($configured = $server->option('ip')) {
                return $configured;
            }
        }
        $ips = (array) ($client->command('CMD_API_SHOW_RESELLER_IPS', [], post: false)['list'] ?? []);
        foreach ($ips as $ip) {
            $status = (string) ($client->command('CMD_API_SHOW_RESELLER_IPS', ['ip' => $ip], post: false)['status'] ?? '');
            if ($mode === 'dedicated' ? $status === 'free' : in_array($status, ['server', 'shared', 'owned'], true)) {
                return $ip;
            }
        }
        if ($mode === 'dedicated') {
            throw new DirectAdminException('No free dedicated IP is available for this reseller.');
        }

        return $ips[0] ?? null;
    }

    private function maxUsernameLength(Server $server): int
    {
        $n = (int) $server->option('max_username_length');

        return $n >= 4 && $n <= 30 ? $n : UsernameGenerator::MAX_LENGTH;
    }

    private function isUsernameTaken(string $message): bool
    {
        return (bool) preg_match('/user(name)?\b.*(exist|taken|in use|already|reserved|not allowed)|(exist|taken|in use|already).*user(name)?\b/i', $message)
            && ! preg_match('/domain/i', $message);
    }

    private function normalizeDomain(string $label): ?string
    {
        $domain = strtolower(trim(preg_replace('~^https?://~i', '', trim($label)), '/ '));

        return preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) ? $domain : null;
    }
}
