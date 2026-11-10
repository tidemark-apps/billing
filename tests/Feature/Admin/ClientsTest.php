<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ClientsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $over = []): array
    {
        return array_merge(['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'currency' => 'USD', 'status' => 'active', 'country' => 'GB'], $over);
    }

    public function test_customers_cannot_open_the_admin_area(): void
    {
        $this->get(route('admin.clients.index'))->assertRedirect();
        $this->actingAs($this->customer())->get(route('admin.clients.index'))->assertForbidden();
    }

    public function test_index_searches_and_filters(): void
    {
        Client::factory()->create(['name' => 'Zed Findable', 'email' => 'zed@example.com']);
        Client::factory()->create(['name' => 'Other Person', 'email' => 'o@example.com']);
        $this->actingAs($this->admin())->get(route('admin.clients.index', ['q' => 'findable']))->assertOk()->assertSee('Zed Findable')->assertDontSee('Other Person');
    }

    public function test_create_client_with_login(): void
    {
        $this->actingAs($this->admin())->post(route('admin.clients.store'), $this->payload(['create_login' => 1, 'password' => 'secret-pass-1']))->assertRedirect();
        $client = Client::where('email', 'ada@example.com')->firstOrFail();
        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertSame($client->id, $user->client_id);
        $this->assertSame(Role::Customer, $user->role);
    }

    public function test_create_client_without_login_and_validation(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.clients.store'), $this->payload())->assertRedirect();
        $this->assertDatabaseCount('users', 1);
        $this->post(route('admin.clients.store'), $this->payload(['create_login' => 1]))->assertSessionHasErrors('password');
        $this->post(route('admin.clients.store'), $this->payload(['country' => 'ZZ']))->assertSessionHasErrors('country');
    }

    public function test_show_edit_and_add_contact(): void
    {
        $client = Client::factory()->create();
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.clients.show', $client))->assertOk()->assertSee($client->email);
        $this->put(route('admin.clients.update', $client), $this->payload(['name' => 'Renamed', 'email' => $client->email]))->assertRedirect();
        $this->assertSame('Renamed', $client->refresh()->name);
        $this->post(route('admin.clients.contacts.store', $client), ['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'password123'])->assertRedirect();
        $this->assertSame($client->id, User::where('email', 'bob@example.com')->value('client_id'));
    }

    public function test_only_admins_delete_and_live_clients_are_protected(): void
    {
        $client = Client::factory()->create();
        $this->actingAs(User::factory()->staff()->create())->delete(route('admin.clients.destroy', $client))->assertForbidden();
        $this->actingAs($this->admin())->delete(route('admin.clients.destroy', $client))->assertRedirect(route('admin.clients.index'));
        $this->assertSoftDeleted($client);

        $busy = Client::factory()->create();
        Service::create(['client_id' => $busy->id, 'status' => 'active', 'billing_cycle' => 'monthly', 'currency' => 'USD', 'amount' => 100]);
        $this->delete(route('admin.clients.destroy', $busy))->assertSessionHas('error');
        $this->assertNotSoftDeleted($busy);
    }

    public function test_impersonation_start_and_refuses_staff(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $this->actingAs($admin)->post(route('admin.impersonate.start', $customer))->assertRedirect(route('portal.dashboard'));
        $this->assertSame($customer->id, auth()->id());
        $this->assertSame($admin->id, session('impersonator_id'));

        $this->actingAs($admin)->post(route('admin.impersonate.start', User::factory()->staff()->create()))->assertForbidden();
    }

    public function test_impersonation_stop_returns_to_staff(): void
    {
        if (! Route::has('admin.impersonate.stop')) {
            $this->markTestSkipped('admin.impersonate.stop is registered in routes/web.php by the parent.');
        }
        $admin = $this->admin();
        $customer = $this->customer();
        $this->actingAs($customer)->withSession(['impersonator_id' => $admin->id])->post(route('admin.impersonate.stop'))->assertRedirect(route('admin.dashboard'));
        $this->assertSame($admin->id, auth()->id());
    }

    public function test_index_has_status_tabs_with_counts_and_filters_and_sorts(): void
    {
        Client::factory()->create(['name' => 'Alpha Active', 'company_name' => null, 'status' => 'active']);
        Client::factory()->create(['name' => 'Beta Inactive', 'company_name' => null, 'status' => 'inactive']);
        Client::factory()->create(['name' => 'Gamma Internal', 'company_name' => null, 'status' => 'active', 'is_internal' => true]);
        $this->actingAs($this->admin());
        $this->get(route('admin.clients.index'))->assertOk()->assertSee('Client views')->assertSee('Alpha Active')->assertSee('Beta Inactive');
        $this->get(route('admin.clients.index', ['view' => 'inactive']))->assertOk()->assertSee('Beta Inactive')->assertDontSee('Alpha Active');
        $this->get(route('admin.clients.index', ['view' => 'internal']))->assertOk()->assertSee('Gamma Internal')->assertDontSee('Alpha Active');
        $this->get(route('admin.clients.index', ['sort' => 'client', 'dir' => 'asc']))->assertOk()->assertSeeInOrder(['Alpha Active', 'Beta Inactive', 'Gamma Internal']);
        $this->get(route('admin.clients.index', ['view' => 'bogus']))->assertSessionHasErrors('view');
    }

    public function test_address_lines_put_city_before_region_and_postcode(): void
    {
        $c = Client::factory()->make(['address_1' => '1 Main St', 'address_2' => null, 'city' => 'Portland', 'state' => 'OR', 'postcode' => '97201', 'country' => 'US']);
        $this->assertContains('Portland, OR 97201', $c->addressLines());
        $this->assertSame('London, SW1A 1AA', Client::factory()->make(['city' => 'London', 'state' => null, 'postcode' => 'SW1A 1AA'])->cityLine());
    }

    public function test_show_has_summary_and_danger_zone(): void
    {
        $client = Client::factory()->create();
        $this->actingAs($this->admin())->get(route('admin.clients.show', $client))->assertOk()->assertSee('Lifetime paid')->assertSee('Open balance')->assertSee('Danger zone');
    }
}
