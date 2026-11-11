<?php

namespace Tests\Feature\System;

use App\Models\Client;
use App\Services\InvoiceService;
use App\Support\Plan;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhitelabelTest extends TestCase
{
    use RefreshDatabase;

    private function planFile(array $data): void
    {
        $file = tempnam(sys_get_temp_dir(), 'plan');
        file_put_contents($file, json_encode($data));
        config(['billing.plan_file' => $file]);
    }

    public function test_off_by_default_and_without_a_plan_file(): void
    {
        $this->assertFalse(Plan::whitelabel());
        $this->planFile(['name' => 'starter']);
        $this->assertFalse(Plan::whitelabel());
        $this->actingAs($this->admin())->get('/admin')->assertOk()->assertSee('Powered by')->assertSee('assets/favicon.svg');
    }

    public function test_the_plan_flag_removes_tidemark_branding(): void
    {
        $this->planFile(['name' => 'starter', 'whitelabel' => true]);
        Settings::set('company.name', 'Acme Hosting');
        $this->assertTrue(Plan::whitelabel());

        $this->actingAs($this->admin())->get('/admin')->assertOk()
            ->assertDontSee('Powered by')->assertDontSee('Tidemark')->assertDontSee('favicon.svg')->assertDontSee('apple-touch-icon')
            ->assertSee('Acme Hosting');

        $invoice = app(InvoiceService::class)->create(Client::factory()->create(), [['description' => 'Hosting', 'unit_price' => 1000, 'taxable' => false]], ['issue' => true, 'send' => false]);
        auth()->logout();
        $this->get(route('invoice.public', $invoice->uuid))->assertOk()->assertDontSee('Powered by')->assertDontSee('Tidemark');
    }

    public function test_a_logo_becomes_the_icon_and_the_header_brand(): void
    {
        $this->planFile(['whitelabel' => true]);
        Settings::set('company.logo_url', 'https://cdn.example.com/logo.png');
        $this->actingAs($this->admin())->get('/admin')->assertOk()->assertSee('<link rel="icon" href="https://cdn.example.com/logo.png">', false);
    }

    public function test_the_usage_report_shows_the_flag(): void
    {
        $this->planFile(['whitelabel' => true]);
        $this->assertTrue(Plan::report()['whitelabel']);
    }
}
