<?php

namespace Tests\Feature\Portal;

use App\Enums\BillingCycle;
use App\Http\Controllers\StoreController;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderFlowUxTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $attrs = []): Product
    {
        $p = Product::factory()->priced(1000, BillingCycle::Monthly, 500)->create($attrs + ['label_prompt' => 'Domain name']);
        $p->prices()->create(['cycle' => BillingCycle::Annually, 'currency' => $p->prices()->first()->currency, 'amount' => 10000, 'setup_fee' => 500]);

        return $p->refresh();
    }

    public function test_savings_compare_a_longer_cycle_with_repeating_the_shortest(): void
    {
        $p = $this->product();
        $this->assertSame(['annually' => 17], StoreController::savings($p->load('prices')->prices->sortBy(fn ($x) => $x->cycle->months())->values()));
        $this->get(route('store.show', $p))->assertOk()->assertSee('Save 17%');
    }

    public function test_no_saving_badge_when_the_longer_cycle_is_not_cheaper(): void
    {
        $p = Product::factory()->priced(1000)->create();
        $p->prices()->create(['cycle' => BillingCycle::Annually, 'currency' => $p->prices()->first()->currency, 'amount' => 12000, 'setup_fee' => 0]);
        $this->get(route('store.show', $p->refresh()))->assertOk()->assertDontSee('Save ');
    }

    public function test_multi_line_descriptions_render_as_a_checklist_and_single_lines_as_text(): void
    {
        $multi = $this->product(['description' => "10 GB disk\nFree SSL"]);
        $this->get(route('store.index'))->assertOk()->assertSee('class="checklist"', false)->assertSee('Free SSL');
        $single = $this->product(['description' => 'Just one line', 'name' => 'Solo']);
        $this->get(route('store.show', $single))->assertOk()->assertSee('Just one line')->assertSee('Continue to review');
    }

    public function test_product_page_preselects_cycle_and_label_and_confirm_page_links_back(): void
    {
        $p = $this->product();
        $this->get(route('store.show', ['product' => $p->slug, 'cycle' => 'annually', 'label' => 'acme.test']))
            ->assertOk()->assertSee('value="acme.test"', false)->assertSee('value="annually" checked', false);
        $user = $this->customer();
        $res = $this->actingAs($user)->get(route('portal.order.create', ['product' => $p->slug, 'cycle' => 'annually', 'label' => 'acme.test']))->assertOk();
        $res->assertSee('Review your order')->assertSee('What happens next')->assertSee(e(route('store.show', ['product' => $p->slug, 'cycle' => 'annually', 'label' => 'acme.test'])), false);
    }

    public function test_placing_an_order_shows_a_confirmation_on_the_invoice(): void
    {
        $p = $this->product();
        $user = $this->customer();
        $this->actingAs($user)->followingRedirects()->post(route('portal.order.store'), ['product' => $p->slug, 'cycle' => 'monthly', 'label' => 'acme.test'])
            ->assertOk()->assertSee('Order placed')->assertSee('Pay it below');
    }

    public function test_trial_order_shows_trial_started_on_the_service(): void
    {
        $p = $this->product(['trial_days' => 14]);
        $user = $this->customer();
        $this->actingAs($user)->followingRedirects()->post(route('portal.order.store'), ['product' => $p->slug, 'cycle' => 'monthly', 'label' => 'acme.test'])
            ->assertOk()->assertSee('Your free trial has started');
    }

    public function test_used_trial_is_explained_on_the_product_page(): void
    {
        $p = $this->product(['trial_days' => 14]);
        $user = $this->customer();
        $this->actingAs($user)->post(route('portal.order.store'), ['product' => $p->slug, 'cycle' => 'monthly', 'label' => 'a.test']);
        $this->get(route('store.show', $p))->assertOk()->assertSee('already used the free trial');
    }

    public function test_product_without_a_price_shows_an_explanation_not_a_bare_404_page(): void
    {
        $p = Product::factory()->create(['name' => 'Priceless']);
        $this->get(route('store.show', $p))->assertNotFound()->assertSee("can't be ordered online");
    }
}
