<?php

namespace Plugins\Enhance;

use App\Models\Server;
use App\Models\Service;
use App\Plugins\AbstractModule;
use App\Support\ProvisionResult;

class EnhanceModule extends AbstractModule
{
    public function key(): string
    {
        return 'enhance';
    }

    public function label(): string
    {
        return 'Enhance';
    }

    public function description(): string
    {
        return 'Creates a customer organisation, owner login, plan subscription and (optionally) a website on an Enhance panel.';
    }

    public function usesServers(): bool
    {
        return true;
    }

    public function serverFields(): array
    {
        return [
            ['key' => 'org_id', 'label' => 'Parent organisation ID', 'type' => 'text', 'required' => true, 'help' => 'UUID of the reseller/master organisation customers are created under. The server "password" field is the API access token.'],
        ];
    }

    public function productFields(): array
    {
        return [
            ['key' => 'plan_id', 'label' => 'Plan ID', 'type' => 'number', 'required' => true, 'help' => 'Numeric Enhance plan id. Use "Test connection" on the server to list available plans.'],
            ['key' => 'create_website', 'label' => 'Create a website for the service label', 'type' => 'checkbox', 'help' => 'Uses the service label (a domain name) as the website domain.'],
            ['key' => 'php_version', 'label' => 'PHP version', 'type' => 'select', 'options' => ['' => 'Panel default', 'php85' => '8.5', 'php84' => '8.4', 'php83' => '8.3', 'php82' => '8.2', 'php81' => '8.1', 'php80' => '8.0']],
        ];
    }

    public function testServer(Server $server): ProvisionResult
    {
        try {
            $client = new EnhanceClient($server);
            if ($client->orgId() === '') {
                return ProvisionResult::fail('Set the parent organisation ID on the server.');
            }
            // PlansListing = {items: Plan[], total: int}; paginate with offset/limit.
            $plans = [];
            do {
                $page = $client->get('orgs/'.$client->orgId().'/plans', ['limit' => 100, 'offset' => count($plans)]);
                $items = (array) $page->json('items', []);
                $plans = array_merge($plans, $items);
            } while ($items && count($plans) < (int) $page->json('total', 0));
            $list = collect($plans)->map(fn ($p) => '#'.$p['id'].' '.$p['name'])->implode(', ');

            return ProvisionResult::ok('Connected. Plans: '.($list ?: 'none found'));
        } catch (\Throwable $e) {
            return ProvisionResult::fail($e->getMessage());
        }
    }

    public function create(Service $service): ProvisionResult
    {
        $server = $service->server;
        if (! $server) {
            return ProvisionResult::fail('No server assigned.');
        }
        $planId = (int) $service->product?->moduleOption('plan_id');
        if (! $planId) {
            return ProvisionResult::fail('The product has no Enhance plan ID.');
        }
        $client = new EnhanceClient($server);
        $parent = $client->orgId();
        $customer = $service->client;
        $password = $this->generatePassword();
        $orgId = null;

        try {
            $orgId = $client->post("orgs/$parent/customers", ['name' => $customer->displayName().' #'.$service->id])->json('id');
            $loginId = $client->post('logins', ['email' => $customer->email, 'password' => $password, 'name' => $customer->name ?: $customer->displayName()], ['orgId' => $orgId])->json('id');
            $memberId = $client->post("orgs/$orgId/members", ['loginId' => $loginId, 'roles' => ['Owner']])->json('id');
            $subscriptionId = $client->post("orgs/$parent/customers/$orgId/subscriptions", ['planId' => $planId, 'friendlyName' => $service->title()])->json('id');

            $data = ['org_id' => $orgId, 'login_id' => $loginId, 'member_id' => $memberId, 'subscription_id' => $subscriptionId, 'login_email' => $customer->email, 'panel_url' => 'https://'.$server->hostname.($server->port && (int) $server->port !== 443 ? ':'.$server->port : '')];

            $domain = strtolower(trim((string) $service->label));
            if ($service->product?->moduleOption('create_website') && $this->looksLikeDomain($domain)) {
                $body = ['domain' => $domain, 'subscriptionId' => $subscriptionId];
                if ($php = $service->product->moduleOption('php_version')) {
                    $body['phpVersion'] = $php;
                }
                $data['website_id'] = $client->post("orgs/$orgId/websites", $body)->json('id');
                $data['domain'] = $domain;
            }
        } catch (\Throwable $e) {
            if ($orgId) {
                try {
                    $this->deleteOrg($client, $orgId);
                } catch (\Throwable) {
                    // best effort rollback; the failure is reported below
                }
            }

            return ProvisionResult::fail($e->getMessage());
        }

        return ProvisionResult::ok('Enhance account created.', $data, ['password' => $password]);
    }

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
        $orgId = $service->data('org_id');
        if (! $service->server || ! $orgId) {
            return ProvisionResult::ok('Nothing to delete remotely.');
        }
        try {
            $forced = $this->deleteOrg(new EnhanceClient($service->server), $orgId);
        } catch (\Throwable $e) {
            return ProvisionResult::fail($e->getMessage());
        }

        return ProvisionResult::ok($forced ? 'Customer organisation deleted.' : 'Customer organisation marked as deleted (soft delete; the token may not force-delete).');
    }

    public function changePackage(Service $service): ProvisionResult
    {
        $planId = (int) $service->product?->moduleOption('plan_id');
        $orgId = $service->data('org_id');
        $subId = $service->data('subscription_id');
        if (! $service->server || ! $orgId || ! $subId || ! $planId) {
            return ProvisionResult::fail('Missing remote subscription or plan id.');
        }
        try {
            (new EnhanceClient($service->server))->patch("orgs/$orgId/subscriptions/$subId", ['planId' => $planId]);
        } catch (\Throwable $e) {
            return ProvisionResult::fail($e->getMessage());
        }

        return ProvisionResult::ok('Plan changed.', ['plan_id' => $planId]);
    }

    public function adminActions(): array
    {
        return ['login' => 'Log in to Enhance'];
    }

    public function clientActions(): array
    {
        return ['login' => 'Log in to control panel'];
    }

    public function runAction(Service $service, string $action, string $area): ProvisionResult
    {
        if ($action !== 'login') {
            return parent::runAction($service, $action, $area);
        }
        $orgId = $service->data('org_id');
        $memberId = $service->data('member_id');
        if (! $service->server || ! $orgId || ! $memberId) {
            return ProvisionResult::fail('This service has no Enhance login.');
        }
        try {
            $response = (new EnhanceClient($service->server))->get("orgs/$orgId/members/$memberId/sso");
            $url = $response->json();
            if (! is_string($url)) {
                $url = trim($response->body(), "\" \n\r\t");
            }
        } catch (\Throwable $e) {
            return ProvisionResult::fail($e->getMessage());
        }
        if (! is_string($url) || ! str_starts_with($url, 'http')) {
            return ProvisionResult::fail('Enhance did not return a login link.');
        }

        return ProvisionResult::redirect($url);
    }

    /**
     * DELETE /orgs/{org_id}: force=true wipes all data but the spec restricts it to privileged MO members (403 otherwise),
     * so fall back to the default soft delete. Returns whether the forced delete was used.
     */
    private function deleteOrg(EnhanceClient $client, string $orgId): bool
    {
        try {
            $client->delete("orgs/$orgId", ['force' => 'true']);

            return true;
        } catch (\RuntimeException $e) {
            if (! str_contains($e->getMessage(), 'HTTP 403')) {
                throw $e;
            }
        }
        $client->delete("orgs/$orgId");

        return false;
    }

    private function setSuspended(Service $service, bool $suspended): ProvisionResult
    {
        $orgId = $service->data('org_id');
        $subId = $service->data('subscription_id');
        if (! $service->server || ! $orgId || ! $subId) {
            return ProvisionResult::fail('Missing remote subscription id.');
        }
        try {
            (new EnhanceClient($service->server))->patch("orgs/$orgId/subscriptions/$subId", ['isSuspended' => $suspended]);
        } catch (\Throwable $e) {
            return ProvisionResult::fail($e->getMessage());
        }

        return ProvisionResult::ok($suspended ? 'Subscription suspended.' : 'Subscription unsuspended.');
    }

    private function looksLikeDomain(string $d): bool
    {
        return (bool) preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $d);
    }
}
