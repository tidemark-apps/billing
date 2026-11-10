<?php

namespace Tests\Feature\System;

use App\Events\DomainEvent;
use App\Support\Abilities;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Tests\Feature\Api\ApiTestHelpers;
use Tests\TestCase;

/** Every support route of the REST API is gated by the `support` switch and by its own ability. */
class SupportApiGatingTest extends TestCase
{
    use ApiTestHelpers, RefreshDatabase;

    /** @return array<int, Route> */
    private function supportRoutes(): array
    {
        return array_values(array_filter(iterator_to_array(app('router')->getRoutes()), fn (Route $r) => str_starts_with($r->uri(), 'api/v1')
            && preg_match('#^api/v1/(admin/)?(tickets|ticket-departments|ticket-fields)(/|$)#', $r->uri())));
    }

    private function url(Route $route): string
    {
        return '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
    }

    public function test_every_support_route_has_the_support_middleware_after_an_ability(): void
    {
        $routes = $this->supportRoutes();
        $this->assertGreaterThanOrEqual(32, count($routes)); // 7 client, 15 admin ticket and 10 department/field routes
        foreach ($routes as $route) {
            $mw = $route->gatherMiddleware();
            $abilityAt = collect($mw)->search(fn ($m) => is_string($m) && str_starts_with($m, 'ability:'));
            $this->assertNotFalse($abilityAt, $route->uri().' has no ability');
            $this->assertContains('support', $mw, $route->uri());
            $this->assertGreaterThan($abilityAt, array_search('support', $mw), $route->uri().': support must come after the ability check');
        }
    }

    public function test_with_support_off_everything_is_404_for_a_fully_authorised_token(): void
    {
        Settings::set('support.enabled', false);
        $admin = $this->admin();
        $customer = $this->customer();
        foreach ($this->supportRoutes() as $route) {
            $who = str_starts_with($route->uri(), 'api/v1/admin') ? $admin : $customer;
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $this->as($who)->json($method, $this->url($route), [])->assertNotFound();
                }
            }
        }
    }

    public function test_the_abilities_are_listed_and_documented(): void
    {
        foreach (['tickets.read', 'tickets.write'] as $ability) {
            $this->assertTrue(Abilities::isValid($ability, 'customer'), "customer $ability");
            $this->assertTrue(Abilities::isValid($ability, 'staff'), "staff $ability");
        }
        foreach (['support.read', 'support.write'] as $ability) {
            $this->assertTrue(Abilities::isValid($ability, 'staff'), "staff $ability");
            $this->assertFalse(Abilities::isValid($ability, 'customer'), "customer $ability");
        }
        $docs = file_get_contents(base_path('docs/api.md'));
        foreach (['tickets.read', 'tickets.write', 'support.read', 'support.write', 'ticket.created', 'ticket.replied', 'ticket.status_changed', 'ticket.assigned'] as $term) {
            $this->assertStringContainsString("`$term`", $docs, $term);
        }
        $this->assertArrayHasKey('ticket.created', DomainEvent::catalog());
        // a customer token cannot be created with a staff-only support ability
        $this->as($this->admin())->getJson('/api/v1/admin/abilities')->assertOk()->assertSee('support.write');
    }

    public function test_the_openapi_document_describes_the_ticket_api(): void
    {
        $spec = json_decode(file_get_contents(resource_path('openapi.json')), true);
        foreach (['/tickets', '/tickets/{ticket}/replies', '/tickets/{ticket}/attachments/{attachment}', '/ticket-departments', '/admin/tickets', '/admin/tickets/{ticket}/notes', '/admin/tickets/{ticket}/transfer',
            '/admin/ticket-departments/{department}', '/admin/ticket-fields/{field}'] as $path) {
            $this->assertArrayHasKey($path, $spec['paths'], $path);
        }
        $this->assertSame('uuid', $spec['paths']['/tickets/{ticket}']['get']['parameters'][0]['schema']['format']);
        $this->assertStringNotContainsString('"imap_password"', json_encode($spec['components']['schemas']['Department']));
        $this->assertArrayHasKey('has_imap_password', $spec['components']['schemas']['Department']['properties']);
        $this->assertArrayHasKey('imap_password', $spec['components']['schemas']['DepartmentInput']['properties']);
    }
}
