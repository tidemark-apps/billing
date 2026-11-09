<?php

namespace Tests\Feature\Api;

use App\Models\AutopayAttempt;
use App\Models\PaymentMethod;
use App\Support\ChargeResult;
use App\Support\Settings;
use Tests\Feature\Payments\FakeSavedGateway;
use Tests\Feature\Payments\PaymentsTestCase;

/** Saved payment methods, paying with them and automatic payment over the customer and admin APIs. */
class SavedPaymentMethodsTest extends PaymentsTestCase
{
    use ApiTestHelpers;

    // ---- customer API ---------------------------------------------------------------------------------------------

    public function test_a_customer_lists_only_their_own_active_methods_without_tokens(): void
    {
        $user = $this->customer();
        $other = $this->customer();
        $mine = $this->method($user->client, ['reference' => 'tok_secret_mine', 'is_default' => true]);
        $second = $this->method($user->client, ['reference' => 'tok_secret_second', 'is_default' => false, 'autopay' => false, 'brand' => 'mastercard', 'last4' => '4444']);
        $this->method($user->client, ['removed_at' => now(), 'is_default' => false]);
        $this->method($other->client);

        $res = $this->as($user)->getJson('/api/v1/payment-methods')->assertOk()->assertJsonCount(2, 'data');
        $res->assertJsonPath('data.0.uuid', $mine->uuid)->assertJsonPath('data.0.is_default', true)->assertJsonPath('data.0.display_name', 'Visa ending 4242')
            ->assertJsonPath('data.0.expiry', '12/2099')->assertJsonPath('data.0.autopay', true)->assertJsonPath('data.1.uuid', $second->uuid);
        $res->assertJsonPath('meta.gateways.0.key', 'fakecard');
        $this->assertStringNotContainsString('tok_secret', $res->getContent());
        foreach (['reference', 'customer_reference', 'data'] as $hidden) {
            $res->assertJsonMissingPath("data.0.$hidden");
        }
        $this->as($user)->getJson("/api/v1/payment-methods/{$second->uuid}")->assertOk()->assertJsonPath('data.last4', '4444');
    }

    public function test_setup_returns_the_gateways_redirect_url(): void
    {
        $user = $this->customer();

        $url = $this->as($user)->postJson('/api/v1/payment-methods/setup', ['gateway' => 'fakecard'])->assertOk()->json('data.redirect_url');
        $this->assertStringStartsWith('https://setup.test/start?return=', $url);
        $this->assertStringContainsString(urlencode('/portal/payment-methods/return/fakecard'), $url);
        $this->assertStringNotContainsString('autopay', urldecode($url));

        $with = $this->as($user)->postJson('/api/v1/payment-methods/setup', ['gateway' => 'fakecard', 'autopay' => true])->assertOk()->json('data.redirect_url');
        $this->assertStringContainsString('autopay=1', urldecode($with));

        $this->as($user)->postJson('/api/v1/payment-methods/setup', ['gateway' => 'nope'])->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $this->as($user)->postJson('/api/v1/payment-methods/setup', [])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->assertSame(0, PaymentMethod::count());
    }

    public function test_setup_is_refused_for_a_gateway_that_cannot_handle_the_clients_currency(): void
    {
        $user = $this->customer(['currency' => 'JPY']);

        $this->as($user)->postJson('/api/v1/payment-methods/setup', ['gateway' => 'fakecard'])->assertStatus(422);
    }

    public function test_default_and_autopay_can_be_changed(): void
    {
        $user = $this->customer();
        $a = $this->method($user->client, ['is_default' => true, 'autopay' => true]);
        $b = $this->method($user->client, ['is_default' => false, 'autopay' => false]);

        $this->as($user)->patchJson("/api/v1/payment-methods/{$b->uuid}", ['is_default' => true])->assertOk()->assertJsonPath('data.is_default', true)->assertJsonPath('data.autopay', false);
        $this->assertFalse($a->refresh()->is_default);
        $this->as($user)->patchJson("/api/v1/payment-methods/{$b->uuid}", ['autopay' => true])->assertOk()->assertJsonPath('data.autopay', true)->assertJsonPath('data.is_default', true);
        $this->as($user)->patchJson("/api/v1/payment-methods/{$a->uuid}", ['autopay' => false])->assertOk()->assertJsonPath('data.autopay', false);
        $this->assertTrue($b->refresh()->autopay);
        $this->assertDatabaseHas('activity_logs', ['action' => 'payment_method.autopay_on']);

        $this->as($user)->patchJson("/api/v1/payment-methods/{$a->uuid}", [])->assertStatus(422);
        $this->as($user)->patchJson("/api/v1/payment-methods/{$a->uuid}", ['is_default' => false])->assertStatus(422);
        $this->as($user)->patchJson("/api/v1/payment-methods/{$a->uuid}", ['autopay' => 'maybe'])->assertStatus(422);
    }

    public function test_a_customer_removes_a_method_and_the_default_moves_on(): void
    {
        $user = $this->customer();
        $a = $this->method($user->client, ['is_default' => true]);
        $b = $this->method($user->client, ['is_default' => false, 'autopay' => false]);

        $this->as($user)->deleteJson("/api/v1/payment-methods/{$a->uuid}")->assertOk()->assertJsonPath('data.removed', true)->assertJsonPath('data.gateway_confirmed', true);
        $this->assertTrue($a->refresh()->isRemoved());
        $this->assertFalse($a->autopay);
        $this->assertTrue($b->refresh()->is_default);
        $this->as($user)->getJson("/api/v1/payment-methods/{$a->uuid}")->assertNotFound();
        $this->as($user)->deleteJson("/api/v1/payment-methods/{$a->uuid}")->assertNotFound();

        FakeSavedGateway::$deleteOk = false;
        $this->as($user)->deleteJson("/api/v1/payment-methods/{$b->uuid}")->assertOk()->assertJsonPath('data.removed', true)->assertJsonPath('data.gateway_confirmed', false);
        $this->assertTrue($b->refresh()->isRemoved());
    }

    public function test_customers_cannot_touch_other_clients_methods(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        $theirs = $this->method($b->client, ['autopay' => false, 'is_default' => false]);

        $this->as($a)->getJson("/api/v1/payment-methods/{$theirs->uuid}")->assertNotFound();
        $this->as($a)->patchJson("/api/v1/payment-methods/{$theirs->uuid}", ['autopay' => true])->assertNotFound();
        $this->as($a)->patchJson("/api/v1/payment-methods/{$theirs->uuid}", ['is_default' => true])->assertNotFound();
        $this->as($a)->deleteJson("/api/v1/payment-methods/{$theirs->uuid}")->assertNotFound();
        $theirs->refresh();
        $this->assertFalse($theirs->autopay);
        $this->assertFalse($theirs->is_default);
        $this->assertFalse($theirs->isRemoved());
        $this->as($a)->getJson('/api/v1/payment-methods')->assertJsonCount(0, 'data');
    }

    public function test_paying_an_invoice_with_a_saved_method(): void
    {
        $user = $this->customer();
        $method = $this->method($user->client, ['autopay' => false]); // autopay would charge the invoice the moment it is created
        $invoice = $this->invoice($user->client, 2500);

        $res = $this->as($user)->postJson("/api/v1/invoices/{$invoice->uuid}/pay-saved", ['payment_method' => $method->uuid])->assertOk();
        $res->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.invoice.status', 'paid')->assertJsonPath('data.invoice.balance.amount', 0);
        $this->assertSame('paid', $invoice->refresh()->status->value);
        $this->assertNotNull($method->refresh()->last_used_at);
        $this->assertCount(1, FakeSavedGateway::$keys);

        // already paid
        $this->as($user)->postJson("/api/v1/invoices/{$invoice->uuid}/pay-saved", ['payment_method' => $method->uuid])->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $this->assertCount(1, FakeSavedGateway::$keys);
    }

    public function test_declined_pending_and_requires_action_charges(): void
    {
        $user = $this->customer();
        $method = $this->method($user->client, ['autopay' => false]);
        $invoice = $this->invoice($user->client);

        FakeSavedGateway::$results = [ChargeResult::failed('Card declined')];
        $this->as($user)->postJson("/api/v1/invoices/{$invoice->uuid}/pay-saved", ['payment_method' => $method->uuid])->assertStatus(422)
            ->assertJsonPath('error.code', 'payment_failed')->assertJsonPath('error.message', 'Card declined');

        FakeSavedGateway::$results = [ChargeResult::requiresAction()];
        $this->as($user)->postJson("/api/v1/invoices/{$invoice->uuid}/pay-saved", ['payment_method' => $method->uuid])->assertOk()->assertJsonPath('data.status', 'requires_action')->assertJsonPath('data.invoice.status', 'open');

        FakeSavedGateway::$results = [ChargeResult::pending('ch_pending')];
        $this->as($user)->postJson("/api/v1/invoices/{$invoice->uuid}/pay-saved", ['payment_method' => $method->uuid])->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.invoice.status', 'open');
        $this->assertSame('open', $invoice->refresh()->status->value);
    }

    public function test_an_expired_or_unusable_method_cannot_pay(): void
    {
        $user = $this->customer();
        $expired = $this->method($user->client, ['exp_month' => 1, 'exp_year' => 2020, 'autopay' => false]);
        $invoice = $this->invoice($user->client);

        $this->as($user)->postJson("/api/v1/invoices/{$invoice->uuid}/pay-saved", ['payment_method' => $expired->uuid])->assertStatus(422)->assertJsonPath('error.code', 'payment_failed');
        $this->assertSame([], FakeSavedGateway::$keys);
        $this->as($user)->postJson("/api/v1/invoices/{$invoice->uuid}/pay-saved", [])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_paying_respects_ownership_of_both_the_invoice_and_the_method(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        $mineMethod = $this->method($a->client, ['autopay' => false]);
        $theirMethod = $this->method($b->client, ['autopay' => false]);
        $mineInvoice = $this->invoice($a->client);
        $theirInvoice = $this->invoice($b->client);
        $draft = $this->makeInvoice($a->client, false);

        $this->as($a)->postJson("/api/v1/invoices/{$mineInvoice->uuid}/pay-saved", ['payment_method' => $theirMethod->uuid])->assertNotFound();
        $this->as($a)->postJson("/api/v1/invoices/{$theirInvoice->uuid}/pay-saved", ['payment_method' => $mineMethod->uuid])->assertNotFound();
        $this->as($a)->postJson("/api/v1/invoices/{$draft->uuid}/pay-saved", ['payment_method' => $mineMethod->uuid])->assertNotFound();
        $this->assertSame([], FakeSavedGateway::$keys);
        $this->assertSame('open', $theirInvoice->refresh()->status->value);
    }

    public function test_customer_routes_need_a_token_and_their_abilities(): void
    {
        $user = $this->customer();
        $method = $this->method($user->client, ['autopay' => false]);
        $invoice = $this->invoice($user->client);
        $calls = [
            ['getJson', '/api/v1/payment-methods', []],
            ['getJson', "/api/v1/payment-methods/{$method->uuid}", []],
            ['postJson', '/api/v1/payment-methods/setup', ['gateway' => 'fakecard']],
            ['patchJson', "/api/v1/payment-methods/{$method->uuid}", ['autopay' => false]],
            ['deleteJson', "/api/v1/payment-methods/{$method->uuid}", []],
            ['postJson', "/api/v1/invoices/{$invoice->uuid}/pay-saved", ['payment_method' => $method->uuid]],
        ];
        foreach ($calls as [$verb, $url, $body]) {
            $this->flushHeaders()->{$verb}($url, $body)->assertStatus(401);
            $this->as($this->staffUser())->{$verb}($url, $body)->assertStatus(403);
        }
        // read-only and unrelated abilities
        $this->as($user, ['payment-methods.read'])->postJson('/api/v1/payment-methods/setup', ['gateway' => 'fakecard'])->assertStatus(403);
        $this->as($user, ['payment-methods.read'])->patchJson("/api/v1/payment-methods/{$method->uuid}", ['autopay' => false])->assertStatus(403);
        $this->as($user, ['payment-methods.read'])->deleteJson("/api/v1/payment-methods/{$method->uuid}")->assertStatus(403);
        $this->as($user, ['payment-methods.write'])->getJson('/api/v1/payment-methods')->assertStatus(403);
        $this->as($user, ['payment-methods.read', 'payment-methods.write', 'invoices.read'])->postJson("/api/v1/invoices/{$invoice->uuid}/pay-saved", ['payment_method' => $method->uuid])->assertStatus(403);
        $this->as($user, ['invoices.pay'])->postJson("/api/v1/invoices/{$invoice->uuid}/pay-saved", ['payment_method' => $method->uuid])->assertOk();
    }

    // ---- staff API --------------------------------------------------------------------------------------------------

    public function test_staff_list_and_remove_a_clients_methods(): void
    {
        $client = $this->customer()->client;
        $other = $this->customer()->client;
        $a = $this->method($client, ['reference' => 'tok_hidden']);
        $b = $this->method($client, ['is_default' => false]);
        $this->method($client, ['removed_at' => now(), 'is_default' => false]);
        $foreign = $this->method($other);
        $staff = $this->staffUser();

        $res = $this->as($staff)->getJson("/api/v1/admin/clients/{$client->id}/payment-methods")->assertOk()->assertJsonCount(2, 'data');
        $res->assertJsonPath('data.0.uuid', $a->uuid)->assertJsonPath('data.0.client_id', $client->id);
        $this->assertStringNotContainsString('tok_hidden', $res->getContent());
        $res->assertJsonMissingPath('data.0.reference');

        $this->as($staff)->deleteJson("/api/v1/admin/clients/{$client->id}/payment-methods/{$foreign->uuid}")->assertNotFound(); // belongs to another client
        $this->assertFalse($foreign->refresh()->isRemoved());
        $this->as($staff)->deleteJson("/api/v1/admin/clients/{$client->id}/payment-methods/{$b->uuid}")->assertOk()->assertJsonPath('data.removed', true)->assertJsonPath('data.gateway_confirmed', true);
        $this->assertTrue($b->refresh()->isRemoved());
        $this->assertDatabaseHas('activity_logs', ['action' => 'client.payment_method_removed']);
        FakeSavedGateway::$deleteOk = false;
        $this->as($staff)->deleteJson("/api/v1/admin/clients/{$client->id}/payment-methods/{$a->uuid}")->assertOk()->assertJsonPath('data.gateway_confirmed', false);
        $this->as($staff)->getJson("/api/v1/admin/clients/{$client->id}/payment-methods")->assertJsonCount(0, 'data');
        $this->as($staff)->getJson('/api/v1/admin/clients/999999/payment-methods')->assertNotFound();
    }

    public function test_staff_method_routes_are_scoped_by_ability(): void
    {
        $client = $this->customer()->client;
        $method = $this->method($client);
        $staff = $this->staffUser();

        $this->as($staff, ['clients.read', 'clients.write', 'payment-methods.write'])->getJson("/api/v1/admin/clients/{$client->id}/payment-methods")->assertStatus(403);
        $this->as($staff, ['payment-methods.read'])->deleteJson("/api/v1/admin/clients/{$client->id}/payment-methods/{$method->uuid}")->assertStatus(403);
        $this->as($staff, ['payment-methods.read'])->getJson("/api/v1/admin/clients/{$client->id}/payment-methods")->assertOk();
        $this->as($staff, ['payment-methods.write'])->deleteJson("/api/v1/admin/clients/{$client->id}/payment-methods/{$method->uuid}")->assertOk();
        $this->assertFalse(PaymentMethod::active()->where('id', $method->id)->exists());
    }

    public function test_staff_read_autopay_attempts_with_filters(): void
    {
        $c1 = $this->customer()->client;
        $c2 = $this->customer()->client;
        $m1 = $this->method($c1, ['autopay' => false]);
        $i1 = $this->invoice($c1);
        $i2 = $this->invoice($c2);
        AutopayAttempt::create(['invoice_id' => $i1->id, 'payment_method_id' => $m1->id, 'attempt' => 1, 'status' => 'failed', 'gateway' => 'fakecard', 'message' => 'Declined', 'created_at' => '2026-11-01 10:00:00', 'updated_at' => now()]);
        AutopayAttempt::create(['invoice_id' => $i1->id, 'payment_method_id' => $m1->id, 'attempt' => 2, 'status' => 'success', 'gateway' => 'fakecard', 'reference' => 'ch_9', 'created_at' => '2026-11-03 10:00:00', 'updated_at' => now()]);
        AutopayAttempt::create(['invoice_id' => $i2->id, 'payment_method_id' => null, 'attempt' => 1, 'status' => 'requires_action', 'gateway' => 'other', 'created_at' => '2026-11-05 10:00:00', 'updated_at' => now()]);
        $staff = $this->staffUser();

        $all = $this->as($staff)->getJson('/api/v1/admin/autopay/attempts')->assertOk()->assertJsonCount(3, 'data')->assertJsonStructure(['data', 'links', 'meta']);
        $all->assertJsonPath('data.0.status', 'requires_action')->assertJsonPath('data.0.client_id', $c2->id)->assertJsonPath('data.0.invoice_number', $i2->number)->assertJsonPath('data.0.payment_method_uuid', null);
        $all->assertJsonPath('data.1.payment_method_uuid', $m1->uuid)->assertJsonPath('data.1.invoice_uuid', $i1->uuid)->assertJsonPath('data.1.reference', 'ch_9');

        $this->as($staff)->getJson('/api/v1/admin/autopay/attempts?status=failed')->assertJsonCount(1, 'data')->assertJsonPath('data.0.message', 'Declined');
        $this->as($staff)->getJson("/api/v1/admin/autopay/attempts?client_id={$c1->id}")->assertJsonCount(2, 'data');
        $this->as($staff)->getJson("/api/v1/admin/autopay/attempts?invoice_id={$i2->id}")->assertJsonCount(1, 'data');
        $this->as($staff)->getJson('/api/v1/admin/autopay/attempts?gateway=other')->assertJsonCount(1, 'data');
        $this->as($staff)->getJson('/api/v1/admin/autopay/attempts?from=2026-11-02&to=2026-11-03')->assertJsonCount(1, 'data')->assertJsonPath('data.0.attempt', 2);
        $this->as($staff)->getJson('/api/v1/admin/autopay/attempts?per_page=1')->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
        $this->as($staff)->getJson('/api/v1/admin/autopay/attempts?status=bogus')->assertStatus(422);
        $this->as($staff)->getJson('/api/v1/admin/autopay/attempts?from=yesterday')->assertStatus(422);

        $this->flushHeaders()->getJson('/api/v1/admin/autopay/attempts')->assertStatus(401);
        $this->as($this->customer())->getJson('/api/v1/admin/autopay/attempts')->assertStatus(403);
        $this->as($staff, ['payment-methods.read', 'invoices.read'])->getJson('/api/v1/admin/autopay/attempts')->assertStatus(403);
    }

    public function test_autopay_settings_are_a_settings_section(): void
    {
        $admin = $this->admin();

        $res = $this->as($admin)->getJson('/api/v1/admin/settings/autopay')->assertOk()->assertJsonPath('data.section', 'autopay');
        $keys = collect($res->json('data.groups'))->pluck('fields')->flatten(1)->pluck('key')->all();
        $this->assertSame(['autopay.enabled', 'autopay.days_before_due', 'autopay.renewals_at_creation', 'autopay.retry_days', 'autopay.max_attempts'], $keys);
        $this->as($admin)->getJson('/api/v1/admin/settings')->assertJsonFragment(['section' => 'autopay', 'editable' => true]);

        $this->as($admin)->putJson('/api/v1/admin/settings/autopay', ['settings' => ['autopay.enabled' => false, 'autopay.max_attempts' => 6, 'autopay.retry_days' => '2,4']])->assertOk();
        $this->assertFalse(Settings::bool('autopay.enabled', true));
        $this->assertSame('6', (string) Settings::get('autopay.max_attempts'));
        $this->assertSame('2,4', Settings::get('autopay.retry_days'));
        // the same values show up on the billing section
        $billing = collect($this->as($admin)->getJson('/api/v1/admin/settings/billing')->json('data.groups'))->pluck('fields')->flatten(1)->firstWhere('key', 'autopay.max_attempts');
        $this->assertEquals(6, $billing['value']);

        $this->as($admin)->putJson('/api/v1/admin/settings/autopay', ['settings' => ['autopay.max_attempts' => 'many']])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['settings.autopay.max_attempts']]]);
        $this->as($admin)->putJson('/api/v1/admin/settings/autopay', ['settings' => ['billing.due_days' => 3]])->assertStatus(422); // not an autopay setting
        $this->as($admin)->putJson('/api/v1/admin/settings/autopay', ['settings' => ['autopay.max_attempts' => null]])->assertOk();
        $this->assertNull(Settings::get('autopay.max_attempts'));

        $this->as($this->staffUser())->getJson('/api/v1/admin/settings/autopay')->assertStatus(403);
        $this->as($this->staffUser())->putJson('/api/v1/admin/settings/autopay', ['settings' => []])->assertStatus(403);
        $this->as($admin, ['invoices.read'])->getJson('/api/v1/admin/settings/autopay')->assertStatus(403);
        $this->flushHeaders()->getJson('/api/v1/admin/settings/autopay')->assertStatus(401);
    }

    public function test_the_security_settings_section_controls_staff_two_factor(): void
    {
        $admin = $this->admin();

        $this->as($admin)->getJson('/api/v1/admin/settings/security')->assertOk()->assertJsonPath('data.groups.0.fields.0.key', 'security.require_staff_2fa')->assertJsonPath('data.groups.0.fields.0.default', false);
        $this->as($admin)->putJson('/api/v1/admin/settings/security', ['settings' => ['security.require_staff_2fa' => true]])->assertOk();
        $this->assertTrue(Settings::bool('security.require_staff_2fa'));
        $this->as($this->staffUser())->putJson('/api/v1/admin/settings/security', ['settings' => ['security.require_staff_2fa' => false]])->assertStatus(403);
    }
}
