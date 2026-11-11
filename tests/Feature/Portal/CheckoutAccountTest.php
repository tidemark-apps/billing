<?php

namespace Tests\Feature\Portal;

use App\Models\Client;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutAccountTest extends TestCase
{
    use RefreshDatabase;

    private function plan(array $attrs = []): Product
    {
        return Product::factory()->priced(2500)->create(['name' => 'Starter', 'slug' => 'starter', 'is_active' => true, 'is_visible' => true] + $attrs);
    }

    private function details(array $over = []): array
    {
        return $over + ['product' => 'starter', 'cycle' => 'monthly', 'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'company_name' => 'Analytical Ltd', 'address_1' => '1 Engine Row', 'city' => 'London',
            'postcode' => 'N1 1AA', 'country' => 'GB', 'password' => 'correct-horse-9'];
    }

    public function test_a_guest_ordering_goes_to_the_account_step_not_the_login_page(): void
    {
        $this->plan();

        $this->get(route('portal.order.create', ['product' => 'starter', 'cycle' => 'monthly']))
            ->assertRedirect(route('checkout.account', ['product' => 'starter', 'cycle' => 'monthly']));
    }

    public function test_the_account_step_shows_the_order_a_sign_up_form_and_a_way_to_log_in(): void
    {
        $this->plan();

        $this->get(route('checkout.account', ['product' => 'starter', 'cycle' => 'monthly']))->assertOk()
            ->assertSee('Create your account')->assertSee('Starter')->assertSee('$25.00')
            ->assertSee('Already have an account?')->assertSee('href="'.route('login').'"', false)
            ->assertSee('name="address_1"', false)->assertSee('name="country"', false);
    }

    public function test_choosing_to_log_in_instead_returns_to_the_review_step_afterwards(): void
    {
        $this->plan();
        $user = User::factory()->create(['email' => 'back@example.com', 'password' => 'correct-horse-9']);

        $this->get(route('checkout.account', ['product' => 'starter', 'cycle' => 'monthly']))->assertOk();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'correct-horse-9'])
            ->assertRedirect(route('portal.order.create', ['product' => 'starter', 'cycle' => 'monthly']));
    }

    public function test_signing_up_here_creates_the_account_and_continues_to_the_review_step(): void
    {
        $this->plan();

        $this->post(route('checkout.account.store'), $this->details())
            ->assertRedirect(route('portal.order.create', ['product' => 'starter', 'cycle' => 'monthly']));

        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $client = Client::findOrFail($user->client_id);
        $this->assertSame('London', $client->city);
        $this->assertSame('GB', $client->country);
        $this->assertSame('Analytical Ltd', $client->company_name);
        $this->get(route('portal.order.create', ['product' => 'starter', 'cycle' => 'monthly']))->assertOk()->assertSee('Review your order');
    }

    public function test_the_address_is_required_and_an_existing_email_points_to_log_in(): void
    {
        $this->plan();
        User::factory()->create(['email' => 'ada@example.com']);

        $this->post(route('checkout.account.store'), $this->details(['address_1' => '', 'postcode' => '']))->assertSessionHasErrors(['address_1', 'postcode']);
        $this->post(route('checkout.account.store'), $this->details())->assertSessionHasErrors(['email']);
        $this->assertGuest();
    }

    public function test_add_ons_and_the_reference_survive_the_account_step(): void
    {
        $plan = $this->plan();
        $addon = Product::factory()->priced(500)->create(['name' => 'Extra', 'slug' => 'extra', 'kind' => 'addon', 'addon_for' => [$plan->id], 'is_active' => true, 'is_visible' => true]);

        $this->get(route('checkout.account', ['product' => 'starter', 'cycle' => 'monthly', 'addons' => ['extra']]))->assertOk()->assertSee('Extra')->assertSee('name="addons[]"', false);
        $this->post(route('checkout.account.store'), $this->details(['addons' => ['extra']]))
            ->assertRedirect(route('portal.order.create', ['product' => 'starter', 'cycle' => 'monthly', 'addons' => ['extra']]));
    }

    public function test_a_signed_in_customer_skips_the_account_step(): void
    {
        $this->plan();

        $this->actingAs($this->customer())->get(route('checkout.account', ['product' => 'starter', 'cycle' => 'monthly']))
            ->assertRedirect(route('portal.order.create', ['product' => 'starter', 'cycle' => 'monthly']));
    }

    public function test_with_sign_up_closed_guests_still_get_the_login_page(): void
    {
        $this->plan();
        config(['billing.registration' => false]);

        $this->get(route('portal.order.create', ['product' => 'starter', 'cycle' => 'monthly']))->assertRedirect(route('login'));
        $this->get(route('checkout.account', ['product' => 'starter', 'cycle' => 'monthly']))->assertNotFound();
    }

    public function test_an_unknown_plan_goes_back_to_the_store(): void
    {
        $this->get(route('checkout.account', ['product' => 'nope', 'cycle' => 'monthly']))->assertRedirect(route('store.index'));
    }
}
