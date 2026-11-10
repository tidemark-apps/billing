<?php

namespace Tests\Feature\System;

use App\Models\Client;
use App\Models\User;
use App\Support\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function plan(array $limits, string $name = 'starter'): void
    {
        $file = sys_get_temp_dir().'/billing-plan-'.uniqid().'.json';
        file_put_contents($file, json_encode(['name' => $name, 'limits' => $limits]));
        config(['billing.plan_file' => $file]);
    }

    public function test_no_plan_file_means_no_limits(): void
    {
        Client::factory()->count(3)->create();

        $this->assertNull(Plan::limit('clients'));
        $this->assertSame(['clients' => null, 'staff' => null], Plan::current()['limits']);
    }

    public function test_a_client_over_the_limit_is_refused(): void
    {
        $this->plan(['clients' => 2, 'staff' => null]);
        Client::factory()->count(2)->create();

        $this->expectExceptionMessage('Your plan allows 2 clients');
        Client::factory()->create();
    }

    public function test_the_admin_screen_shows_the_limit_message(): void
    {
        $admin = $this->admin();
        $this->plan(['clients' => 0, 'staff' => null]);

        $response = $this->actingAs($admin)->from('/admin/clients/create')->post('/admin/clients', [
            'name' => 'Ada', 'email' => 'ada@example.com', 'currency' => 'USD', 'status' => 'active',
        ]);

        $response->assertRedirect('/admin/clients/create')->assertSessionHas('error');
        $this->assertSame(0, Client::count());
    }

    public function test_a_staff_user_over_the_limit_is_refused_but_customers_are_not(): void
    {
        $this->admin();
        $this->plan(['clients' => null, 'staff' => 1]);

        $this->customer();
        $this->assertSame(1, Plan::usage('staff'));

        $this->expectExceptionMessage('Your plan allows 1 staff users');
        User::factory()->admin()->create();
    }

    public function test_reactivating_or_promoting_counts_as_adding_staff(): void
    {
        $this->admin();
        $inactive = User::factory()->admin()->create(['is_active' => false]);
        $this->plan(['clients' => null, 'staff' => 1]);

        $this->expectExceptionMessage('Your plan allows 1 staff users');
        $inactive->update(['is_active' => true]);
    }

    public function test_existing_users_keep_working_over_the_limit_and_usage_reports_it(): void
    {
        $a = $this->admin();
        $this->admin();
        $this->plan(['clients' => null, 'staff' => 1]);

        $a->update(['name' => 'Renamed']);
        Artisan::call('billing:usage');
        $report = json_decode(Artisan::output(), true);

        $this->assertSame('starter', $report['plan']);
        $this->assertSame(2, $report['usage']['staff']);
        $this->assertSame(['staff'], $report['over_limit']);
    }
}
