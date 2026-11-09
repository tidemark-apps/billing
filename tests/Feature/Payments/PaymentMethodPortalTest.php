<?php

namespace Tests\Feature\Payments;

use App\Models\Client;
use App\Models\PaymentMethod;
use App\Support\ChargeResult;

class PaymentMethodPortalTest extends PaymentsTestCase
{
    public function test_page_lists_methods_and_gateways(): void
    {
        $user = $this->customer();
        $this->method($user->client);
        $this->actingAs($user)->get(route('portal.payment-methods.index'))->assertOk()->assertSee('Visa ending 4242')->assertSee('Add with Fake card');
    }

    public function test_nav_item_present(): void
    {
        $user = $this->customer();
        $this->actingAs($user)->get(route('portal.dashboard'))->assertSee('Payment methods');
    }

    public function test_add_flow_saves_first_method_as_default_with_autopay_choice(): void
    {
        $user = $this->customer();
        $res = $this->actingAs($user)->post(route('portal.payment-methods.setup'), ['gateway' => 'fakecard', 'autopay' => 1]);
        $res->assertRedirectContains('https://setup.test/start');
        $this->get(route('portal.payment-methods.return', ['fakecard', 'autopay' => 1, 'ok' => 1, 'ref' => 'tok_new']))->assertRedirect(route('portal.payment-methods.index'));

        $m = PaymentMethod::where('client_id', $user->client_id)->sole();
        $this->assertTrue($m->is_default);
        $this->assertTrue($m->autopay);
        $this->assertSame('4242', $m->last4);

        $this->get(route('portal.payment-methods.return', ['fakecard', 'ok' => 0]))->assertSessionHas('error');
    }

    public function test_make_default_toggle_autopay_and_remove(): void
    {
        $user = $this->customer();
        $a = $this->method($user->client);
        $b = $this->method($user->client, ['is_default' => false, 'autopay' => false]);
        $this->actingAs($user);

        $this->post(route('portal.payment-methods.default', $b->uuid))->assertRedirect();
        $this->assertTrue($b->refresh()->is_default);
        $this->assertFalse($a->refresh()->is_default);

        $this->post(route('portal.payment-methods.autopay', $b->uuid), ['autopay' => 1]);
        $this->assertTrue($b->refresh()->autopay);

        $this->delete(route('portal.payment-methods.destroy', $b->uuid))->assertSessionHas('status');
        $this->assertTrue($b->refresh()->isRemoved());
        $this->assertTrue($a->refresh()->is_default); // default falls back to the remaining method
        $this->assertFalse($b->autopay);
    }

    public function test_cannot_touch_another_clients_methods(): void
    {
        $mine = $this->customer();
        $other = $this->customer();
        $theirs = $this->method($other->client);
        $this->actingAs($mine);

        $this->post(route('portal.payment-methods.default', $theirs->uuid))->assertNotFound();
        $this->post(route('portal.payment-methods.autopay', $theirs->uuid), ['autopay' => 0])->assertNotFound();
        $this->delete(route('portal.payment-methods.destroy', $theirs->uuid))->assertNotFound();
        $this->get(route('portal.payment-methods.index'))->assertDontSee('4242');
        $this->assertFalse($theirs->refresh()->isRemoved());

        $invoice = $this->invoice($mine->client, 1000, now()->addDays(5)->toDateString());
        $this->post(route('portal.payment-methods.pay', $invoice), ['method' => $theirs->uuid])->assertNotFound();
        $theirInvoice = $this->invoice($other->client, 1000, now()->addDays(5)->toDateString());
        $mineMethod = $this->method($mine->client);
        $this->post(route('portal.payment-methods.pay', $theirInvoice), ['method' => $mineMethod->uuid])->assertNotFound();
    }

    public function test_guests_are_redirected(): void
    {
        $this->get(route('portal.payment-methods.index'))->assertRedirect(route('login'));
    }

    public function test_pay_invoice_with_saved_method_from_portal_and_option_is_shown(): void
    {
        $user = $this->customer();
        $m = $this->method($user->client);
        $invoice = $this->invoice($user->client, 1000, now()->addDays(5)->toDateString());
        $this->actingAs($user);

        $this->get(route('portal.invoices.show', $invoice))->assertSee('Pay with saved method');
        $this->get(route('invoice.public', $invoice->uuid))->assertSee('Pay with saved method');

        FakeSavedGateway::$results = [ChargeResult::failed('Nope')];
        $this->post(route('portal.payment-methods.pay', $invoice), ['method' => $m->uuid])->assertSessionHas('error');
        $this->post(route('portal.payment-methods.pay', $invoice), ['method' => $m->uuid])->assertSessionHas('status');
        $this->assertSame('paid', $invoice->refresh()->status->value);
    }

    public function test_public_page_hides_saved_option_for_guests_and_other_clients(): void
    {
        $owner = $this->customer();
        $this->method($owner->client);
        $invoice = $this->invoice($owner->client, 1000, now()->addDays(5)->toDateString());

        $this->get(route('invoice.public', $invoice->uuid))->assertOk()->assertDontSee('Pay with saved method');
        $this->actingAs($this->customer())->get(route('invoice.public', $invoice->uuid))->assertDontSee('Pay with saved method');
    }

    public function test_admin_can_see_and_remove_methods(): void
    {
        $client = Client::factory()->create();
        $m = $this->method($client);
        $this->actingAs($this->admin());

        $this->get(route('admin.clients.show', $client))->assertOk()->assertSee('Visa ending 4242');
        $this->delete(route('admin.clients.payment-methods.destroy', [$client, $m->uuid]))->assertSessionHas('status');
        $this->assertTrue($m->refresh()->isRemoved());

        $other = Client::factory()->create();
        $m2 = $this->method($other);
        $this->delete(route('admin.clients.payment-methods.destroy', [$client, $m2->uuid]))->assertNotFound();
    }
}
