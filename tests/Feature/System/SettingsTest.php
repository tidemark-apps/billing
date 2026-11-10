<?php

namespace Tests\Feature\System;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_saves_general_and_billing_settings(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/settings')->assertOk()->assertSee('Your business');
        $this->actingAs($admin)->put('/admin/settings/general', ['s' => ['company__name' => 'Acme Hosting', 'company__email' => 'hi@acme.test', 'general__registration' => '0']])->assertRedirect('/admin/settings/general');
        $this->actingAs($admin)->put('/admin/settings/billing', ['s' => ['billing__due_days' => '14', 'billing__invoice_prefix' => 'ACME-', 'billing__currency' => 'EUR']])->assertRedirect();

        $this->assertSame('Acme Hosting', Settings::get('company.name'));
        $this->assertFalse(Settings::bool('general.registration'));
        $this->assertEquals(14, Settings::get('billing.due_days'));
        $this->assertSame('ACME-', Settings::get('billing.invoice_prefix'));
    }

    public function test_blank_password_keeps_the_stored_secret(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put('/admin/settings/email', ['s' => ['mail__password' => 'hunter2', 'mail__host' => 'smtp.test']]);
        $this->actingAs($admin)->put('/admin/settings/email', ['s' => ['mail__password' => '', 'mail__host' => 'smtp2.test']]);

        $this->assertSame('hunter2', Settings::get('mail.password'));
        $this->assertSame('smtp2.test', Settings::get('mail.host'));
    }

    public function test_settings_are_admin_only_even_for_staff(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get('/admin/settings')->assertForbidden();
        $this->actingAs($staff)->put('/admin/settings/general', ['s' => ['company__name' => 'Hacked']])->assertForbidden();
    }

    public function test_customers_cannot_open_settings(): void
    {
        $this->actingAs($this->customer())->get('/admin/settings')->assertForbidden();
    }

    public function test_cron_url_requires_the_token(): void
    {
        $this->get('/cron/wrong')->assertNotFound();
        config(['billing.cron_token' => 'secret-token']);
        $this->get('/cron/secret-token')->assertOk()->assertSee('ok');
    }

    public function test_settings_tabs_have_a_save_bar_and_group_by_topic(): void
    {
        $admin = $this->admin();
        $page = $this->actingAs($admin)->get('/admin/settings/billing')->assertOk()
            ->assertSee('data-savebar', false)->assertSee('data-dirty-form', false)->assertSee('Save billing settings')
            ->assertSee('Numbering and payment terms')->assertSee('Recurring billing');
        $this->assertSame(1, substr_count($page->getContent(), 'data-dirty-form novalidate'));
    }

    public function test_stored_secrets_are_masked_with_a_replace_button(): void
    {
        $admin = $this->admin();
        Settings::set('mail.password', 'hunter2-secret', true);

        $page = $this->actingAs($admin)->get('/admin/settings/email')->assertOk()->assertSee('data-secret-replace', false)->assertSee('Send test email');
        $this->assertStringNotContainsString('hunter2-secret', $page->getContent());
        $this->assertStringContainsString('name="s[mail__password]" value="" disabled', $page->getContent());
    }

    public function test_validation_errors_show_a_summary_and_inline_message(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->from('/admin/settings/billing')->put('/admin/settings/billing', ['s' => ['billing__due_days' => 'soon']])->assertRedirect('/admin/settings/billing');
        $this->actingAs($admin)->get('/admin/settings/billing')->assertSee('Nothing was saved')->assertSee('field-error', false);
    }

    public function test_payments_tab_masks_gateway_secrets_with_replace_and_offers_the_webhook_url(): void
    {
        $this->enablePlugin('stripe');
        Settings::set('gateway.stripe.secret_key', 'sk_test_topsecret', true);
        $page = $this->actingAs($this->admin())->get('/admin/settings/payments')->assertOk()
            ->assertSee('Save payment settings')->assertSee('data-secret-replace', false)->assertSee(route('webhooks.gateway', 'stripe'), false);
        $this->assertStringNotContainsString('sk_test_topsecret', $page->getContent());
    }
}
