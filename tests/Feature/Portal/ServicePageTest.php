<?php

namespace Tests\Feature\Portal;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Service;
use App\Plugins\AbstractModule;
use App\Plugins\ModuleRegistry;
use App\Support\ProvisionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicePageTest extends TestCase
{
    use RefreshDatabase;

    private function hosted(): array
    {
        app(ModuleRegistry::class)->register(new class extends AbstractModule
        {
            public function key(): string
            {
                return 'pagehost';
            }

            public function label(): string
            {
                return 'Page host';
            }

            public function create(Service $service): ProvisionResult
            {
                return ProvisionResult::ok('ok');
            }

            public function clientActions(): array
            {
                return ['reset_password' => 'Reset password', 'login' => 'Log in to panel'];
            }
        });
        $user = $this->customer();
        $product = Product::factory()->priced(2000)->create(['name' => 'Hosted', 'module' => 'pagehost']);
        $service = Service::create(['client_id' => $user->client->id, 'product_id' => $product->id, 'status' => ServiceStatus::Active, 'billing_cycle' => BillingCycle::Monthly, 'currency' => 'USD', 'amount' => 2000,
            'next_due_date' => now()->addDays(10), 'activated_at' => now(), 'provision' => ['url' => 'https://acme.example.com', 'org_id' => 'org_secretish']]);

        return [$user, $service];
    }

    public function test_the_login_action_is_the_primary_button_and_the_rest_sit_in_the_service_card(): void
    {
        [$user, $service] = $this->hosted();

        $html = $this->actingAs($user)->get(route('portal.services.show', $service))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#svc-hero-actions.*btn primary lg[^>]*>\s*Log in to panel#s', $html);
        $this->assertMatchesRegularExpression('#svc-acts.*Reset password#s', $html);
        $this->assertStringContainsString('data-copy="https://acme.example.com"', $html);
    }

    public function test_billing_summary_adds_active_addons_and_shows_the_payment_method(): void
    {
        [$user, $service] = $this->hosted();
        $addon = Product::factory()->priced(500)->create(['name' => 'Extra', 'kind' => 'addon']);
        Service::create(['client_id' => $user->client->id, 'product_id' => $addon->id, 'parent_service_id' => $service->id, 'status' => ServiceStatus::Active, 'billing_cycle' => BillingCycle::Monthly, 'currency' => 'USD', 'amount' => 500, 'next_due_date' => $service->next_due_date]);
        PaymentMethod::create(['client_id' => $user->client->id, 'gateway' => 'stripe', 'reference' => 'pm_t1', 'brand' => 'visa', 'last4' => '4242', 'is_default' => true]);

        $this->actingAs($user)->get(route('portal.services.show', $service))->assertOk()
            ->assertSee('$25.00')->assertSee('Hosted $20.00 + Extra $5.00')->assertSee('Visa ending 4242');
    }

    public function test_cancel_sits_behind_a_disclosure_and_still_posts(): void
    {
        [$user, $service] = $this->hosted();

        $this->actingAs($user)->get(route('portal.services.show', $service))->assertOk()
            ->assertSee('svc-cancel', false)->assertSee('Cancel this service…');
        $this->actingAs($user)->post(route('portal.services.cancel', $service), ['when' => 'end'])->assertRedirect();
        $this->assertTrue($service->refresh()->cancel_at_period_end || $service->status !== ServiceStatus::Active);
    }
}
