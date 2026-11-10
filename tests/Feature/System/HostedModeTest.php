<?php

namespace Tests\Feature\System;

use App\Providers\AppServiceProvider;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class HostedModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_web_installer_is_closed_in_hosted_mode(): void
    {
        config(['billing.hosted' => true]);

        $this->get('/install')->assertNotFound();
    }

    public function test_mail_transport_cannot_be_changed_in_hosted_mode(): void
    {
        config(['billing.hosted' => true, 'mail.default' => 'log']);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/settings/email')->assertOk()->assertDontSee('SMTP host')->assertSee('From name');
        $this->actingAs($admin)->put('/admin/settings/email', ['s' => ['mail__from_name' => 'Acme Billing', 'mail__mailer' => 'smtp', 'mail__host' => 'evil.example.com']])->assertRedirect();

        $this->assertSame('Acme Billing', Settings::get('mail.from_name'));
        $this->assertNull(Settings::get('mail.host'));
    }

    public function test_the_mail_transport_setting_is_ignored_in_hosted_mode(): void
    {
        config(['billing.hosted' => true, 'mail.default' => 'log']);
        Settings::set('mail.mailer', 'smtp');
        Settings::set('mail.host', 'evil.example.com');
        Settings::set('mail.from_name', 'Acme Billing');

        (new \ReflectionMethod(app()->getProvider(AppServiceProvider::class), 'applyMailSettings'))->invoke(app()->getProvider(AppServiceProvider::class));

        $this->assertSame('log', config('mail.default'));
        $this->assertSame('Acme Billing', config('mail.from.name'));
    }

    public function test_login_link_signs_in_once(): void
    {
        $admin = $this->admin();
        Artisan::call('billing:login-link', ['email' => $admin->email]);
        $url = trim(Artisan::output());

        $this->get($url)->assertRedirect();
        $this->assertAuthenticatedAs($admin);

        auth()->logout();
        $this->get($url)->assertForbidden();
    }

    public function test_login_link_rejects_a_tampered_or_expired_link(): void
    {
        $admin = $this->admin();
        $expired = URL::temporarySignedRoute('login.link', now()->subMinute(), ['user' => $admin->id]);
        $this->get($expired)->assertForbidden();
        $this->get('/login/link/'.$admin->id)->assertForbidden();
        $this->assertGuest();
    }

    public function test_login_link_command_refuses_an_unknown_user(): void
    {
        $this->artisan('billing:login-link', ['email' => 'nobody@example.com'])->assertFailed();
    }

    public function test_login_link_reset_option_prints_a_working_password_link(): void
    {
        $admin = $this->admin();
        Artisan::call('billing:login-link', ['email' => $admin->email, '--reset' => true]);
        $url = trim(Artisan::output());

        $this->assertStringContainsString('/reset-password/', $url);
        $token = explode('?', substr($url, strpos($url, '/reset-password/') + 16))[0];
        $this->post('/reset-password', ['token' => $token, 'email' => $admin->email, 'password' => 'a-new-password-1', 'password_confirmation' => 'a-new-password-1'])->assertRedirect('/login');
        $this->assertTrue(Hash::check('a-new-password-1', $admin->fresh()->password));
    }
}
