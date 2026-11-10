<?php

namespace Tests\Feature\Support;

use App\Models\Department;
use App\Models\Ticket;
use App\Services\Support\ImapPuller;
use App\Services\Support\InboundEmail;
use App\Support\Settings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InboundCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('tickets');
        Mail::fake();
        Settings::set('support.enabled', true);
        Department::create(['name' => 'Sales', 'slug' => 'sales', 'email' => 'sales@example.test']);
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path('tests/Fixtures/mail/'.$name.'.eml'));
    }

    private function pipe(string $raw, array $options = [])
    {
        $this->app->instance('tickets.stdin', $raw);

        return $this->artisan('tickets:pipe', $options);
    }

    public function test_pipe_creates_a_ticket_and_exits_zero_without_echoing_the_message(): void
    {
        $this->pipe($this->fixture('new_plain'))->expectsOutput('created')->doesntExpectOutputToContain('invoice')->assertExitCode(0);

        $this->assertSame(1, Ticket::count());
    }

    public function test_pipe_exits_zero_for_duplicates_and_auto_replies(): void
    {
        $this->pipe($this->fixture('new_plain'))->assertExitCode(0);
        $this->pipe($this->fixture('new_plain'))->expectsOutput('duplicate')->assertExitCode(0);
        $this->pipe($this->fixture('auto_reply'))->expectsOutput('ignored')->assertExitCode(0);

        $this->assertSame(1, Ticket::count());
    }

    public function test_pipe_exits_67_for_an_unknown_recipient_but_not_for_auto_mail(): void
    {
        $this->pipe($this->fixture('unknown_recipient'))->assertExitCode(67);
        $auto = str_replace('sales@example.test', 'nobody@example.test', $this->fixture('auto_reply'));
        $this->pipe($auto)->assertExitCode(0);
    }

    public function test_pipe_department_option_routes_without_recipient_headers(): void
    {
        $this->pipe($this->fixture('unknown_recipient'), ['--department' => 'sales'])->assertExitCode(0);
        $this->assertSame(1, Ticket::count());

        $this->pipe($this->fixture('unknown_recipient'), ['--department' => 'nope'])->assertExitCode(67);
    }

    public function test_pipe_exits_75_on_transient_errors_and_when_support_is_disabled(): void
    {
        $this->mock(InboundEmail::class)->shouldReceive('process')->andThrow(new \RuntimeException('database is down'));
        $this->pipe($this->fixture('new_plain'))->doesntExpectOutputToContain('database')->assertExitCode(75);

        Settings::set('support.enabled', false);
        $this->pipe($this->fixture('new_plain'))->assertExitCode(75);
    }

    public function test_fetch_skips_quietly_when_support_is_disabled(): void
    {
        Settings::set('support.enabled', false);
        $this->mock(ImapPuller::class)->shouldNotReceive('pull');
        Department::where('slug', 'sales')->update(['imap_enabled' => true, 'imap_host' => 'h', 'imap_username' => 'u']);

        $this->artisan('tickets:fetch')->assertExitCode(0);
    }

    public function test_fetch_pulls_every_enabled_department_or_just_the_one_named(): void
    {
        Department::where('slug', 'sales')->update(['imap_enabled' => true, 'imap_host' => 'h', 'imap_username' => 'u']);
        Department::create(['name' => 'Tech', 'slug' => 'tech', 'imap_enabled' => true, 'imap_host' => 'h', 'imap_username' => 'u']);
        Department::create(['name' => 'Billing', 'slug' => 'billing']); // no mailbox
        $pulled = [];
        $this->mock(ImapPuller::class)->shouldReceive('pull')->andReturnUsing(function (Department $d) use (&$pulled) {
            $pulled[] = $d->slug;

            return 2;
        });

        $this->artisan('tickets:fetch')->expectsOutput('sales: 2 messages processed.')->assertExitCode(0);
        $this->assertSame(['sales', 'tech'], $pulled);

        $pulled = [];
        $this->artisan('tickets:fetch', ['--department' => 'tech'])->assertExitCode(0);
        $this->assertSame(['tech'], $pulled);
    }

    public function test_fetch_fails_when_a_mailbox_reports_an_error(): void
    {
        Department::where('slug', 'sales')->update(['imap_enabled' => true, 'imap_host' => 'h', 'imap_username' => 'u']);
        $this->mock(ImapPuller::class)->shouldReceive('pull')->andReturnUsing(function (Department $d) {
            $d->update(['imap_error' => 'Login failed']);

            return 0;
        });

        $this->artisan('tickets:fetch')->expectsOutputToContain('Login failed')->assertExitCode(1);
    }

    public function test_fetch_is_scheduled_every_minute_only_when_enabled(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'tickets:fetch'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->filtersPass($this->app));
        Settings::set('support.enabled', false);
        $this->assertFalse($event->filtersPass($this->app));
    }
}
