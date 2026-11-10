<?php

namespace Tests\Feature\Admin;

use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Plugins\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeModule;
use Tests\Fakes\FakeServerModule;
use Tests\TestCase;

class ServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRegistry::class)->register(new FakeModule);
        app(ModuleRegistry::class)->register(new FakeServerModule);
    }

    public function test_manual_creation_without_activation_leaves_pending_and_creates_no_invoice(): void
    {
        $client = Client::factory()->create();
        $product = Product::factory()->priced(2500)->module('fake')->create();
        $this->actingAs($this->admin())->post(route('admin.services.store'), ['client_id' => $client->id, 'product_id' => $product->id, 'billing_cycle' => 'monthly', 'label' => 'a.com'])->assertRedirect();
        $s = Service::firstOrFail();
        $this->assertSame(ServiceStatus::Pending, $s->status);
        $this->assertSame(2500, $s->amount);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_manual_creation_with_activation_and_price_override(): void
    {
        $client = Client::factory()->create();
        $product = Product::factory()->priced(2500)->module('fake')->create();
        $this->actingAs($this->admin())->post(route('admin.services.store'), ['client_id' => $client->id, 'product_id' => $product->id, 'billing_cycle' => 'monthly', 'amount' => '10.50', 'activate' => 1])->assertRedirect();
        $s = Service::firstOrFail();
        $this->assertSame(ServiceStatus::Active, $s->status);
        $this->assertSame(1050, $s->amount);
        $this->assertSame('fake-account', $s->data('username'));
    }

    public function test_missing_price_without_override_is_rejected(): void
    {
        $client = Client::factory()->create();
        $product = Product::factory()->create();
        $this->actingAs($this->admin())->post(route('admin.services.store'), ['client_id' => $client->id, 'product_id' => $product->id, 'billing_cycle' => 'monthly'])->assertSessionHasErrors('amount');
    }

    public function test_lifecycle_actions_and_secret_reveal(): void
    {
        $client = Client::factory()->create();
        $product = Product::factory()->priced(1000)->module('fake')->create();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.services.store'), ['client_id' => $client->id, 'product_id' => $product->id, 'billing_cycle' => 'monthly', 'activate' => 1]);
        $s = Service::firstOrFail();

        $this->get(route('admin.services.show', $s))->assertOk()->assertDontSee('s3cret')->assertSee('Reveal');
        $this->post(route('admin.services.reveal', $s))->assertOk()->assertSee('s3cret');
        $this->assertDatabaseHas('activity_logs', ['action' => 'service.secrets_revealed']);

        $this->post(route('admin.services.suspend', $s))->assertSessionHas('status');
        $this->assertSame(ServiceStatus::Suspended, $s->refresh()->status);
        $this->post(route('admin.services.unsuspend', $s));
        $this->assertSame(ServiceStatus::Active, $s->refresh()->status);
        $this->post(route('admin.services.cancel', $s), ['immediately' => 1]);
        $this->assertSame(ServiceStatus::Cancelled, $s->refresh()->status);
    }

    public function test_module_action_update_and_index_filters(): void
    {
        $client = Client::factory()->create(['name' => 'Searchable Co', 'company_name' => null]);
        $product = Product::factory()->priced(1000)->module('fakeserver')->create();
        Server::create(['module' => 'fakeserver', 'name' => 's', 'hostname' => 'good.example.com']);
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.services.store'), ['client_id' => $client->id, 'product_id' => $product->id, 'billing_cycle' => 'monthly', 'activate' => 1]);
        $s = Service::firstOrFail();
        $this->assertSame('good.example.com', $s->data('server'));

        $this->post(route('admin.services.action', $s), ['action' => 'ping'])->assertSessionHas('status', 'pong');
        $this->post(route('admin.services.action', $s), ['action' => 'bogus'])->assertSessionHas('error');

        $this->put(route('admin.services.update', $s), ['amount' => '20', 'billing_cycle' => 'annually', 'label' => 'x'])->assertSessionHas('status');
        $this->assertSame(2000, $s->refresh()->amount);

        $this->get(route('admin.services.index', ['q' => 'searchable', 'status' => 'active']))->assertOk()->assertSee('Searchable Co');
        $this->get(route('admin.services.index', ['status' => 'pending']))->assertOk()->assertDontSee('Searchable Co');
    }

    public function test_lists_still_render_after_a_client_with_only_closed_services_is_deleted(): void
    {
        $client = Client::factory()->create(['name' => 'Gone Customer']);
        $product = Product::factory()->priced(2500)->module('fake')->create();
        $service = Service::create([
            'client_id' => $client->id, 'product_id' => $product->id, 'status' => ServiceStatus::Terminated,
            'billing_cycle' => 'monthly', 'amount' => 2500, 'currency' => 'USD', 'label' => 'old.example',
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->delete(route('admin.clients.destroy', $client))->assertRedirect();
        $this->assertSoftDeleted($client);

        $this->actingAs($admin)->get(route('admin.services.index'))->assertOk()->assertSee('old.example')->assertSee('Gone Customer');
        $this->actingAs($admin)->get(route('admin.services.show', $service))->assertOk();
        $this->actingAs($admin)->get(route('admin.invoices.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.payments.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.quotes.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
    }
}
