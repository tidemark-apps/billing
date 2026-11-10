<?php

namespace Tests\Feature\System;

use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostSignInTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        config(['billing.sso_public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
    }

    private function token(array $claims = [], ?string $secret = null): string
    {
        $claims += ['aud' => 'localhost', 'iat' => time(), 'exp' => time() + 120, 'jti' => bin2hex(random_bytes(8)), 'kind' => 'customer'];
        $part = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(sodium_crypto_sign_detached($part, $secret ?? $this->secret)), '+/', '-_'), '=');

        return $part.'.'.$sig;
    }

    public function test_customer_token_signs_in_the_owner_once(): void
    {
        $admin = $this->admin();
        $token = $this->token(['email' => $admin->email]);

        $this->get('/sso?token='.$token)->assertRedirect();
        $this->assertAuthenticatedAs($admin);

        auth()->logout();
        $this->get('/sso?token='.$token)->assertForbidden();
    }

    public function test_support_token_signs_in_logs_the_reason_and_shows_a_banner(): void
    {
        $admin = $this->admin();
        $token = $this->token(['kind' => 'support', 'staff' => ['id' => 's-1', 'name' => 'Sam Support'], 'reason' => 'Invoices missing', 'ticket' => 'T-1042']);

        $this->get('/sso?token='.$token)->assertRedirect();
        $this->assertAuthenticatedAs($admin);
        $this->get('/admin')->assertOk()->assertSee('Support session: Sam Support');
        $this->assertTrue(ActivityLog::where('action', 'auth.support_session')->get()->contains(fn ($a) => str_contains(json_encode($a->properties), 'T-1042')));
    }

    public function test_bad_tokens_are_refused(): void
    {
        $this->admin();
        $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());

        $this->get('/sso?token='.$this->token([], $other))->assertForbidden();
        $this->get('/sso?token='.$this->token(['exp' => time() - 5]))->assertForbidden();
        $this->get('/sso?token='.$this->token(['aud' => 'other.example.com']))->assertForbidden();
        $this->get('/sso?token='.$this->token(['kind' => 'admin']))->assertForbidden();
        $this->get('/sso?token=garbage')->assertForbidden();
        $this->assertGuest();
    }

    public function test_sso_is_off_without_a_public_key(): void
    {
        $this->admin();
        $token = $this->token();
        config(['billing.sso_public_key' => null]);

        $this->get('/sso?token='.$token)->assertForbidden();
    }

    public function test_a_suspended_install_shows_a_banner(): void
    {
        $file = sys_get_temp_dir().'/billing-plan-'.uniqid().'.json';
        file_put_contents($file, json_encode(['suspended' => true, 'suspended_message' => 'Payment failed.']));
        config(['billing.plan_file' => $file]);

        $this->actingAs($this->admin())->get('/admin')->assertOk()->assertSee('This account is suspended')->assertSee('Payment failed.');
    }
}
