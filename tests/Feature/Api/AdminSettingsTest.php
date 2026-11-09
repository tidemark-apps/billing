<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\Support\SettingsSchema;
use App\Models\Setting;
use App\Plugins\AbstractGateway;
use App\Plugins\GatewayRegistry;
use App\Support\CheckoutContext;
use App\Support\CheckoutResult;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/** Settings API: general / billing / email / payments / system. */
class AdminSettingsTest extends TestCase
{
    use ApiTestHelpers, RefreshDatabase;

    private function registerGateway(): void
    {
        app(GatewayRegistry::class)->register(new class extends AbstractGateway
        {
            public function key(): string
            {
                return 'fakepay';
            }

            public function label(): string
            {
                return 'Fake Pay';
            }

            public function fields(): array
            {
                return [
                    ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true],
                    ['key' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['test' => 'Test', 'live' => 'Live']],
                    ['key' => 'sandbox', 'label' => 'Sandbox', 'type' => 'checkbox'],
                ];
            }

            public function checkout($invoice, CheckoutContext $context): CheckoutResult
            {
                return CheckoutResult::redirect('https://pay.test');
            }

            public function webhook(Request $request): Response
            {
                return response('ok');
            }
        });
    }

    public function test_the_api_settings_schema_is_the_admin_screens_schema(): void
    {
        $keys = fn (string $section) => collect(SettingsSchema::fields($section))->pluck('key')->all();

        $this->assertContains('company.name', $keys('general'));
        $this->assertContains('billing.due_days', $keys('billing'));
        $this->assertContains('mail.password', $keys('email'));
        foreach (SettingsSchema::EDITABLE as $section) {
            $this->assertNotEmpty(SettingsSchema::groups($section));
            foreach (SettingsSchema::fields($section) as $field) {
                $this->assertStringNotContainsString('__', $field['key'], 'keys are the real dotted setting names');
            }
        }
    }

    public function test_reading_settings_shows_values_and_masks_secrets(): void
    {
        $admin = $this->admin();
        Settings::set('company.name', 'Acme Hosting');
        Settings::set('mail.password', 'smtp-secret', true);

        $general = $this->as($admin)->getJson('/api/v1/admin/settings/general')->assertOk()->assertJsonPath('data.section', 'general')->json('data.groups');
        $name = collect($general[0]['fields'])->firstWhere('key', 'company.name');
        $this->assertSame('Acme Hosting', $name['value']);
        $this->assertTrue($name['required']);

        $res = $this->as($admin)->getJson('/api/v1/admin/settings/email')->assertOk();
        $password = collect($res->json('data.groups.0.fields'))->firstWhere('key', 'mail.password');
        $this->assertTrue($password['has_value']);
        $this->assertTrue($password['secret']);
        $this->assertArrayNotHasKey('value', $password);
        $this->assertStringNotContainsString('smtp-secret', $res->getContent());

        $this->as($admin)->getJson('/api/v1/admin/settings')->assertOk()->assertJsonCount(7, 'data');
        $this->as($admin)->getJson('/api/v1/admin/settings/nope')->assertNotFound();
    }

    public function test_updating_settings_is_partial_validated_and_never_leaks_secrets(): void
    {
        $admin = $this->admin();
        Settings::set('mail.password', 'smtp-secret', true);
        Settings::set('mail.enabled', 'true');

        $res = $this->as($admin)->putJson('/api/v1/admin/settings/billing', ['settings' => ['billing.due_days' => 14, 'billing.invoice_prefix' => 'ACME-', 'billing.currency' => 'EUR']])->assertOk();
        $this->assertSame('14', (string) Settings::get('billing.due_days'));
        $this->assertSame('ACME-', Settings::get('billing.invoice_prefix'));
        $this->assertSame('EUR', Settings::get('billing.currency'));
        $this->assertSame(14, collect($res->json('data.groups.0.fields'))->firstWhere('key', 'billing.due_days')['value']);

        // a partial update leaves checkboxes and secrets that were not sent alone
        $this->as($admin)->putJson('/api/v1/admin/settings/email', ['settings' => ['mail.from_name' => 'Billing']])->assertOk();
        $this->assertTrue(Settings::bool('mail.enabled'));
        $this->assertSame('smtp-secret', Settings::get('mail.password'));
        // a blank secret keeps the stored one; a new one replaces it (encrypted at rest, never echoed)
        $this->as($admin)->putJson('/api/v1/admin/settings/email', ['settings' => ['mail.password' => '']])->assertOk();
        $this->assertSame('smtp-secret', Settings::get('mail.password'));
        $res = $this->as($admin)->putJson('/api/v1/admin/settings/email', ['settings' => ['mail.password' => 'new-secret', 'mail.enabled' => false]])->assertOk();
        $this->assertSame('new-secret', Settings::get('mail.password'));
        $this->assertFalse(Settings::bool('mail.enabled'));
        $this->assertStringNotContainsString('new-secret', $res->getContent());
        $this->assertNotSame('new-secret', Setting::where('key', 'mail.password')->value('value'));
        $this->assertTrue((bool) Setting::where('key', 'mail.password')->value('is_secret'));

        // null resets a setting
        $this->as($admin)->putJson('/api/v1/admin/settings/billing', ['settings' => ['billing.invoice_prefix' => null]])->assertOk();
        $this->assertNull(Settings::get('billing.invoice_prefix'));

        $this->assertDatabaseHas('activity_logs', ['action' => 'settings.updated']);
    }

    public function test_settings_validation_matches_the_admin_screen(): void
    {
        $admin = $this->admin();

        $this->as($admin)->putJson('/api/v1/admin/settings/billing', ['settings' => ['billing.currency' => 'XXX', 'billing.due_days' => 'soon']])->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['details' => ['settings.billing.currency', 'settings.billing.due_days']]]);
        $this->as($admin)->putJson('/api/v1/admin/settings/general', ['settings' => ['company.name' => '']])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['settings.company.name']]]);
        $this->as($admin)->putJson('/api/v1/admin/settings/general', ['settings' => ['company.email' => 'not-an-email', 'company.logo_url' => 'nope']])->assertStatus(422);
        $this->as($admin)->putJson('/api/v1/admin/settings/general', ['settings' => ['company.typo' => 'x']])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['settings.company.typo']]]);
        $this->as($admin)->putJson('/api/v1/admin/settings/general', [])->assertStatus(422);
        $this->as($admin)->putJson('/api/v1/admin/settings/system', ['settings' => []])->assertStatus(405);
        $this->assertNull(Settings::get('billing.currency'));
    }

    public function test_payment_gateway_settings(): void
    {
        $this->registerGateway();
        $admin = $this->admin();

        $res = $this->as($admin)->getJson('/api/v1/admin/settings/payments')->assertOk();
        $gw = collect($res->json('data.gateways'))->firstWhere('key', 'fakepay');
        $this->assertFalse($gw['enabled']);
        $this->assertFalse($gw['configured'] ?? false);

        $res = $this->as($admin)->putJson('/api/v1/admin/settings/payments', ['gateways' => ['fakepay' => ['enabled' => true, 'title' => 'Card', 'api_key' => 'sk_live_abc', 'mode' => 'live']]])->assertOk();
        $this->assertStringNotContainsString('sk_live_abc', $res->getContent());
        $gw = collect($res->json('data.gateways'))->firstWhere('key', 'fakepay');
        $this->assertTrue($gw['enabled']);
        $this->assertSame('Card', $gw['title']);
        $this->assertTrue(collect($gw['fields'])->firstWhere('key', 'api_key')['has_value']);
        $this->assertSame('live', collect($gw['fields'])->firstWhere('key', 'mode')['value']);
        $this->assertSame('sk_live_abc', Settings::get('gateway.fakepay.api_key'));
        $this->assertTrue((bool) Setting::where('key', 'gateway.fakepay.api_key')->value('is_secret'));

        // partial: enabling state and the stored key survive an unrelated update
        $this->as($admin)->putJson('/api/v1/admin/settings/payments', ['gateways' => ['fakepay' => ['title' => 'Pay by card']]])->assertOk();
        $this->assertTrue(Settings::bool('gateway.fakepay.enabled'));
        $this->assertSame('sk_live_abc', Settings::get('gateway.fakepay.api_key'));
        $this->as($admin)->putJson('/api/v1/admin/settings/payments', ['gateways' => ['fakepay' => ['enabled' => false]]])->assertOk();
        $this->assertFalse(Settings::bool('gateway.fakepay.enabled'));

        $this->as($admin)->putJson('/api/v1/admin/settings/payments', ['gateways' => ['fakepay' => ['mode' => 'moon']]])->assertStatus(422);
        $this->as($admin)->putJson('/api/v1/admin/settings/payments', ['gateways' => ['fakepay' => ['bogus' => 'x']]])->assertStatus(422);
        $this->as($admin)->putJson('/api/v1/admin/settings/payments', ['gateways' => ['ghost' => ['enabled' => true]]])->assertStatus(422);
        $this->as($admin)->putJson('/api/v1/admin/settings/payments', [])->assertStatus(422);
    }

    public function test_system_info_cron_token_and_billing_run(): void
    {
        $admin = $this->admin();
        $this->as($admin)->postJson('/api/v1/admin/settings/system/cron-token')->assertStatus(422)->assertJsonPath('error.code', 'domain_error'); // BILLING_CRON_TOKEN is set in phpunit.xml
        config(['billing.cron_token' => null]);

        $info = $this->as($admin)->getJson('/api/v1/admin/settings/system')->assertOk()->assertJsonPath('data.section', 'system')->assertJsonStructure(['data' => ['info' => ['version', 'php', 'cli_cron']]]);
        $this->assertFalse($info->json('data.info.cron_url_configured'));

        $url = $this->as($admin)->postJson('/api/v1/admin/settings/system/cron-token')->assertOk()->json('data.cron_url');
        $this->assertStringContainsString('/cron/', $url);
        $res = $this->as($admin)->getJson('/api/v1/admin/settings/system')->assertOk()->assertJsonPath('data.info.cron_url_configured', true);
        $this->assertStringNotContainsString(basename($url), $res->getContent(), 'the cron token is shown only when generated');

        $this->as($admin)->postJson('/api/v1/admin/settings/system/run')->assertOk()->assertJsonStructure(['data']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'billing.run']);
    }

    public function test_test_email(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $this->as($admin)->postJson('/api/v1/admin/settings/email/test')->assertOk()->assertJsonPath('data.success', true);
    }

    public function test_settings_are_for_administrators_with_the_right_ability(): void
    {
        $staff = $this->staffUser();
        $customer = $this->customer();
        $admin = $this->admin();
        $write = fn ($u, $abilities = null) => $this->as($u, $abilities)->putJson('/api/v1/admin/settings/general', ['settings' => ['company.name' => 'X']]);

        $this->as($staff)->getJson('/api/v1/admin/settings/general')->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
        $this->as($staff)->getJson('/api/v1/admin/settings')->assertStatus(403);
        $write($staff)->assertStatus(403);
        $this->as($staff)->postJson('/api/v1/admin/settings/system/run')->assertStatus(403);
        $this->as($staff)->postJson('/api/v1/admin/settings/system/cron-token')->assertStatus(403);
        $this->as($staff)->postJson('/api/v1/admin/settings/email/test')->assertStatus(403);

        $this->as($customer)->getJson('/api/v1/admin/settings/general')->assertStatus(403);
        $write($customer)->assertStatus(403);
        $this->flushHeaders()->getJson('/api/v1/admin/settings/general')->assertStatus(401);

        // ability scoping for an administrator's token
        $this->as($admin, ['settings.read'])->getJson('/api/v1/admin/settings/general')->assertOk();
        $write($admin, ['settings.read'])->assertStatus(403);
        $this->as($admin, ['settings.read'])->postJson('/api/v1/admin/settings/system/run')->assertStatus(403);
        $this->as($admin, ['invoices.read'])->getJson('/api/v1/admin/settings/general')->assertStatus(403);
        $write($admin, ['settings.write'])->assertOk();
        $this->as($admin, ['settings.write'])->getJson('/api/v1/admin/settings/general')->assertStatus(403);
    }
}
