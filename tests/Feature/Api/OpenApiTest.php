<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\Support\OpenApi\Operations;
use App\Support\Abilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** resources/openapi.json is generated (`php artisan api:openapi`) from the routes plus metadata; these tests keep it honest. */
class OpenApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_committed_spec_is_up_to_date(): void
    {
        $this->artisan('api:openapi', ['--check' => true])->assertSuccessful();
    }

    public function test_the_command_writes_the_same_document_it_checks(): void
    {
        $path = sys_get_temp_dir().'/openapi-'.uniqid().'.json';
        $this->artisan('api:openapi', ['--path' => $path])->assertSuccessful();
        $this->assertSame(file_get_contents(resource_path('openapi.json')), file_get_contents($path));
        @unlink($path);
    }

    public function test_a_route_without_documentation_fails_the_command(): void
    {
        Route::middleware('api')->prefix('api/v1')->get('/brand-new', fn () => 'x')->name('api.admin.brand-new');

        $this->artisan('api:openapi', ['--check' => true])->assertFailed()->expectsOutputToContain('admin.brand-new');
    }

    public function test_the_spec_is_structurally_sound(): void
    {
        $spec = json_decode(file_get_contents(resource_path('openapi.json')), true);
        $schemas = array_keys($spec['components']['schemas']);
        $this->assertSame('3.1.0', $spec['openapi']);

        $refs = [];
        array_walk_recursive($spec, function ($value, $key) use (&$refs) {
            if ($key === '$ref') {
                $refs[] = $value;
            }
        });
        foreach (array_unique($refs) as $ref) {
            $this->assertStringStartsWith('#/components/schemas/', $ref);
            $this->assertContains(substr($ref, strlen('#/components/schemas/')), $schemas, "Unresolved $ref");
        }

        $ids = [];
        foreach ($spec['paths'] as $path => $ops) {
            preg_match_all('/\{(\w+)\}/', $path, $m);
            foreach ($ops as $method => $op) {
                $label = strtoupper($method).' '.$path;
                $ids[] = $op['operationId'];
                $this->assertNotEmpty($op['summary'], $label);
                $this->assertNotEmpty($op['tags'], $label);
                $declared = array_column(array_filter($op['parameters'] ?? [], fn ($p) => $p['in'] === 'path'), 'name');
                $this->assertSame($m[1], $declared, "Path parameters of $label");
                $this->assertArrayHasKey('429', $op['responses'], $label);
                if ($op['security'] !== []) {
                    $this->assertArrayHasKey('401', $op['responses'], $label);
                }
                if (isset($op['description']) && preg_match_all('/`([a-z-]+\.(?:read|write|pay))`/', $op['description'], $a)) {
                    foreach ($a[1] as $ability) {
                        $this->assertTrue(Abilities::isValid($ability), "$label mentions unknown ability $ability");
                    }
                }
            }
        }
        $this->assertSame($ids, array_values(array_unique($ids)), 'operationIds are unique');
    }

    public function test_admin_operations_state_their_ability_and_pagination(): void
    {
        $spec = json_decode(file_get_contents(resource_path('openapi.json')), true);
        $ops = $spec['paths']['/admin/invoices']['get'];
        $this->assertStringContainsString('`invoices.read`', $ops['description']);
        $this->assertContains('per_page', array_column($ops['parameters'], 'name'));
        $this->assertContains('overdue', array_column($ops['parameters'], 'name'));
        $this->assertSame([], $spec['paths']['/auth/token']['post']['security']);
        $this->assertStringContainsString('Administrators only', $spec['paths']['/admin/settings/{section}']['put']['description']);
        $this->assertStringNotContainsString('Administrators only', $spec['paths']['/admin/invoices']['get']['description']);
        // the token endpoint is public: its only 403 is the two-factor enrolment requirement, and it documents the otp field
        $this->assertStringContainsString('two_factor_enrolment_required', $spec['paths']['/auth/token']['post']['responses']['403']['description']);
        $this->assertArrayHasKey('otp', $spec['paths']['/auth/token']['post']['requestBody']['content']['application/json']['schema']['properties']);
        $this->assertSame('application/pdf', array_key_first($spec['paths']['/invoices/{invoice}/pdf']['get']['responses']['200']['content']));
        $this->assertArrayHasKey('200', $spec['paths']['/admin/services/{service}/usage']['post']['responses']);
    }

    public function test_every_admin_group_is_documented(): void
    {
        $spec = json_decode(file_get_contents(resource_path('openapi.json')), true);
        $paths = array_keys($spec['paths']);
        foreach (['/admin/dashboard', '/admin/servers', '/admin/product-groups', '/admin/settings/{section}', '/admin/plugins/{slug}/settings', '/admin/api-tokens', '/admin/users/{user}/impersonate',
            '/admin/clients/{client}/users', '/admin/activity', '/admin/services/{service}/logs', '/admin/invoices/{invoice}/resend', '/admin/quotes/{quote}/duplicate', '/admin/payments/summary',
            '/admin/autopay/attempts', '/admin/clients/{client}/payment-methods', '/admin/products/{product}/meters', '/admin/services/{service}/usage', '/admin/invoices/{invoice}/pdf', '/payment-methods', '/services/{service}/usage', '/invoices/{invoice}/pay-saved'] as $expected) {
            $this->assertContains($expected, $paths);
        }
        $this->assertGreaterThan(100, count(Operations::all()));
    }
}
