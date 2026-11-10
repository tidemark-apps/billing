<?php

namespace Tests\Feature\System;

use App\Support\Plan;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HostedMailTest extends TestCase
{
    use RefreshDatabase;

    private function send(string $to = 'client@example.com'): void
    {
        Mail::raw('Hello', fn ($m) => $m->to($to)->subject('Test'));
    }

    private function sent(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->all();
    }

    public function test_hosted_mail_gets_a_reply_to_of_the_owner(): void
    {
        config(['billing.hosted' => true, 'mail.default' => 'array']);
        Settings::set('company.email', 'owner@acme.example');
        Settings::set('company.name', 'Acme Hosting');

        $this->send();

        $reply = $this->sent()[0]->getOriginalMessage()->getReplyTo();
        $this->assertSame('owner@acme.example', $reply[0]->getAddress());
        $this->assertSame('Acme Hosting', $reply[0]->getName());
    }

    public function test_self_hosted_mail_is_left_alone(): void
    {
        config(['mail.default' => 'array']);
        Settings::set('company.email', 'owner@acme.example');

        $this->send();

        $this->assertSame([], $this->sent()[0]->getOriginalMessage()->getReplyTo());
    }

    public function test_the_daily_email_allowance_stops_further_mail(): void
    {
        config(['billing.hosted' => true, 'mail.default' => 'array']);
        $file = sys_get_temp_dir().'/billing-plan-'.uniqid().'.json';
        file_put_contents($file, json_encode(['limits' => ['emails_per_day' => 2]]));
        config(['billing.plan_file' => $file]);
        Cache::forget('plan-emails:'.now()->utc()->format('Y-m-d'));

        $this->send('a@example.com');
        $this->send('b@example.com');
        $this->send('c@example.com');

        $this->assertCount(2, $this->sent());
        $this->assertSame(2, Plan::usage('emails_per_day'));
    }

    public function test_no_limit_means_unlimited_email(): void
    {
        config(['billing.hosted' => true, 'mail.default' => 'array']);

        foreach (range(1, 5) as $n) {
            $this->send("c$n@example.com");
        }

        $this->assertCount(5, $this->sent());
    }
}
