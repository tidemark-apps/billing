<?php

namespace Tests\Feature\Portal;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Services\Cancellations;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\ApiTestHelpers;
use Tests\TestCase;

class CancellationsTest extends TestCase
{
    use ApiTestHelpers, RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $group = ProductGroup::create(['name' => 'Hosting', 'slug' => 'hosting']);
        $this->product = Product::factory()->priced(3000)->create(['product_group_id' => $group->id, 'name' => 'Basic']);
    }

    /** A paid, active monthly service with 15 days left in the period. */
    private function liveService($user): Service
    {
        ['service' => $service, 'invoice' => $invoice] = app(OrderService::class)->place($user->client, $this->product, BillingCycle::Monthly);
        app(PaymentService::class)->recordManual($invoice, $invoice->total, 'card');
        $service->refresh()->update(['next_due_date' => now()->addDays(15)->startOfDay()]);

        return $service->refresh();
    }

    private function expectedRefund(Service $service): int
    {
        $end = $service->next_due_date->copy()->startOfDay();
        $days = (int) $end->copy()->subMonthsNoOverflow(1)->diffInDays($end);

        return (int) round(3000 * 15 / $days);
    }

    public function test_by_default_customers_cancel_at_the_end_of_the_term_and_are_not_offered_a_choice(): void
    {
        $user = $this->customer();
        $service = $this->liveService($user);

        $this->actingAs($user)->get(route('portal.services.show', $service))->assertOk()->assertSee('Request cancellation')->assertDontSee('When should it end?');
        $this->post(route('portal.services.cancel', $service), ['when' => 'now'])->assertRedirect();
        $service->refresh();
        $this->assertTrue($service->cancel_at_period_end);
        $this->assertSame(ServiceStatus::Active, $service->status);
    }

    public function test_the_customer_can_choose_when_the_setting_allows_it(): void
    {
        Settings::set('billing.cancel_mode', 'customer_choice');
        $user = $this->customer();
        $service = $this->liveService($user);

        $this->actingAs($user)->get(route('portal.services.show', $service))->assertSee('When should it end?')->assertSee('At the end of the billing term')->assertSee('Immediately');
        $this->post(route('portal.services.cancel', $service), ['when' => 'end']);
        $this->assertTrue($service->refresh()->cancel_at_period_end);

        $other = $this->liveService($user);
        $this->post(route('portal.services.cancel', $other), ['when' => 'now']);
        $this->assertSame(ServiceStatus::Cancelled, $other->refresh()->status);
    }

    public function test_immediate_only_cancels_straight_away(): void
    {
        Settings::set('billing.cancel_mode', 'immediate');
        $user = $this->customer();
        $service = $this->liveService($user);

        $this->actingAs($user)->get(route('portal.services.show', $service))->assertSee('Your service ends straight away.')->assertDontSee('When should it end?');
        $this->post(route('portal.services.cancel', $service));
        $this->assertSame(ServiceStatus::Cancelled, $service->refresh()->status);
    }

    public function test_an_immediate_cancellation_refunds_the_unused_days_when_the_setting_is_on(): void
    {
        Settings::set('billing.cancel_mode', 'immediate');
        Settings::set('billing.cancel_refund', 'true');
        $user = $this->customer();
        $service = $this->liveService($user);
        $expected = $this->expectedRefund($service);

        $this->actingAs($user)->get(route('portal.services.show', $service))->assertSee('You will be refunded');
        $this->post(route('portal.services.cancel', $service))->assertSessionHas('status');
        $refund = Payment::where('type', 'refund')->firstOrFail();
        $this->assertSame($expected, $refund->amount);
        $this->assertGreaterThan(0, $refund->amount);
        $this->assertLessThan(3000, $refund->amount);
        $this->assertStringContainsString('refunded', session('status'));
    }

    public function test_no_refund_when_the_setting_is_off_or_the_cancellation_is_at_term_end(): void
    {
        Settings::set('billing.cancel_mode', 'customer_choice');
        $user = $this->customer();
        $a = $this->liveService($user);
        $b = $this->liveService($user);
        $this->actingAs($user)->post(route('portal.services.cancel', $a), ['when' => 'now']);
        Settings::set('billing.cancel_refund', 'true');
        $this->post(route('portal.services.cancel', $b), ['when' => 'end']);

        $this->assertSame(0, Payment::where('type', 'refund')->count());
    }

    public function test_a_trial_or_unpaid_service_has_nothing_to_refund(): void
    {
        Settings::set('billing.cancel_refund', 'true');
        $user = $this->customer();
        ['service' => $service] = app(OrderService::class)->place($user->client, $this->product, BillingCycle::Monthly);

        $this->assertNull(app(Cancellations::class)->refundPreview($service->refresh()));
    }

    public function test_the_client_api_follows_the_mode(): void
    {
        $user = $this->customer();
        $service = $this->liveService($user);

        $this->as($user)->postJson("/api/v1/services/{$service->uuid}/cancel", ['immediately' => true])->assertStatus(422)->assertSee('end of the billing term');

        Settings::set('billing.cancel_mode', 'customer_choice');
        Settings::set('billing.cancel_refund', 'true');
        $res = $this->as($user)->postJson("/api/v1/services/{$service->uuid}/cancel", ['immediately' => true])->assertOk();
        $res->assertJsonPath('data.status', 'cancelled')->assertJsonPath('meta.refund.status', 'issued');
        $this->assertSame($this->expectedRefund($service), $res->json('meta.refund.amount'));
    }

    public function test_staff_can_refund_per_cancellation_whatever_the_mode(): void
    {
        $admin = $this->admin();
        $a = $this->liveService($this->customer());
        $b = $this->liveService($this->customer());

        $this->actingAs($admin)->post(route('admin.services.cancel', $a), ['immediately' => 1, 'refund' => 1])->assertRedirect();
        $this->assertSame(1, Payment::where('type', 'refund')->count());
        $this->actingAs($admin)->post(route('admin.services.cancel', $b), ['immediately' => 1])->assertRedirect();
        $this->assertSame(1, Payment::where('type', 'refund')->count());
        $this->assertSame(ServiceStatus::Cancelled, $b->refresh()->status);
    }

    public function test_the_settings_are_on_the_billing_tab(): void
    {
        $this->actingAs($this->admin())->get(route('admin.settings.index', 'billing'))->assertOk()->assertSee('When a cancellation takes effect')->assertSee('Refund the unused time');
        $this->put(route('admin.settings.update', 'billing'), ['s' => ['billing__cancel_mode' => 'customer_choice', 'billing__cancel_refund' => '1']])->assertSessionHasNoErrors();
        $this->assertSame('customer_choice', Settings::get('billing.cancel_mode'));
        $this->assertTrue(Settings::bool('billing.cancel_refund'));
        $this->put(route('admin.settings.update', 'billing'), ['s' => ['billing__cancel_mode' => 'nonsense']])->assertSessionHasErrors();
    }
}
