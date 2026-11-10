<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalAccountsApiTest extends TestCase
{
    use ApiTestHelpers, RefreshDatabase;

    public function test_the_admin_api_marks_and_unmarks_a_client_and_reprices_their_services(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::factory()->create();
        $service = $this->makeService($client, null, ['amount' => 1500]);

        $this->as($admin)->patchJson("/api/v1/admin/clients/{$client->id}", ['is_internal' => true])
            ->assertOk()->assertJsonPath('data.is_internal', true);
        $this->assertSame(0, $service->refresh()->amount);

        $this->as($admin)->getJson("/api/v1/admin/services/{$service->id}")->assertOk()->assertJsonPath('data.price.amount', 0);

        $this->as($admin)->patchJson("/api/v1/admin/clients/{$client->id}", ['is_internal' => false])
            ->assertOk()->assertJsonPath('data.is_internal', false);
        $this->assertSame(1500, $service->refresh()->amount);
    }

    public function test_a_service_created_through_the_api_for_an_internal_client_is_free(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::factory()->create(['is_internal' => true]);
        $product = Product::factory()->priced(2000)->create();

        $this->as($admin)->postJson('/api/v1/admin/services', ['client_id' => $client->id, 'product_id' => $product->id, 'billing_cycle' => 'monthly'])
            ->assertCreated();

        $service = Service::where('client_id', $client->id)->firstOrFail();
        $this->assertSame(0, $service->amount);
        $this->assertSame(2000, $service->internal_original_amount);
    }
}
