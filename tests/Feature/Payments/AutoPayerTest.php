<?php

namespace Tests\Feature\Payments;

use App\Enums\InvoiceStatus;
use App\Mail\BillingMail;
use App\Models\AutopayAttempt;
use App\Models\Client;
use App\Models\Payment;
use App\Services\AutoPayer;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\ChargeResult;
use App\Support\Settings;
use Illuminate\Support\Facades\Mail;

class AutoPayerTest extends PaymentsTestCase
{
    private function client(): Client
    {
        return Client::factory()->create(['currency' => 'USD']);
    }

    public function test_invoice_due_today_is_charged_on_issue_and_recorded_once(): void
    {
        $client = $this->client();
        $m = $this->method($client);
        $invoice = $this->invoice($client);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $payment = Payment::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('fakecard', $payment->gateway);
        $this->assertSame(2500, $payment->amount);
        $this->assertNotNull($m->refresh()->last_used_at);

        // running again (scheduler) neither charges nor duplicates
        $this->assertSame(1, count(FakeSavedGateway::$keys));
        app(AutoPayer::class)->run();
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, count(FakeSavedGateway::$keys));
    }

    public function test_invoice_not_due_yet_waits_for_the_scheduler(): void
    {
        $client = $this->client();
        $this->method($client);
        $invoice = $this->invoice($client, 1000, now()->addDays(10)->toDateString());
        $this->assertSame(InvoiceStatus::Open, $invoice->refresh()->status);

        app(AutoPayer::class)->run();
        $this->assertSame(InvoiceStatus::Open, $invoice->refresh()->status);

        $this->travel(10)->days();
        $this->assertSame(1, app(AutoPayer::class)->run()['paid']);
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
    }

    public function test_days_before_due_setting_and_renewals_at_creation(): void
    {
        $client = $this->client();
        $this->method($client);
        Settings::set('autopay.days_before_due', 3);
        $invoice = $this->invoice($client, 1000, now()->addDays(3)->toDateString());
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);

        Settings::set('autopay.days_before_due', 0);
        Settings::set('autopay.renewals_at_creation', 'true');
        $inv = app(InvoiceService::class)->create($client, [['description' => 'Renewal', 'unit_price' => 900, 'taxable' => false, 'kind' => 'renewal']], ['issue' => true, 'due_date' => now()->addDays(14)->toDateString()]);
        $this->assertSame(InvoiceStatus::Paid, $inv->refresh()->status);
    }

    public function test_no_autopay_method_means_no_charge(): void
    {
        $client = $this->client();
        $this->method($client, ['autopay' => false]);
        $invoice = $this->invoice($client);
        $this->assertSame(InvoiceStatus::Open, $invoice->refresh()->status);
        $this->assertSame([], FakeSavedGateway::$keys);
    }

    public function test_disabled_gateway_and_unsupported_currency_are_skipped(): void
    {
        $client = $this->client();
        $this->method($client);
        Settings::set('gateway.fakecard.enabled', false);
        $this->assertSame(InvoiceStatus::Open, $this->invoice($client)->refresh()->status);

        Settings::set('gateway.fakecard.enabled', true);
        $yen = Client::factory()->create(['currency' => 'JPY']);
        $this->method($yen);
        $this->assertSame(InvoiceStatus::Open, $this->invoice($yen, 5000)->refresh()->status);
        $this->assertSame([], FakeSavedGateway::$keys);
    }

    public function test_failure_emails_customer_and_retries_on_schedule_then_succeeds(): void
    {
        $client = $this->client();
        $this->method($client);
        FakeSavedGateway::$results = [ChargeResult::failed('Card declined')];
        $invoice = $this->invoice($client);

        $this->assertSame(InvoiceStatus::Open, $invoice->refresh()->status);
        $this->assertSame('failed', AutopayAttempt::where('invoice_id', $invoice->id)->sole()->status);
        Mail::assertSent(BillingMail::class, fn ($m) => str_contains($m->mailSubject, 'Payment failed') && str_contains(implode(' ', $m->lines), 'Card declined'));

        app(AutoPayer::class)->run(); // same day: too early
        $this->assertCount(1, FakeSavedGateway::$keys);

        $this->travel(1)->days();
        app(AutoPayer::class)->run();
        $this->assertCount(2, FakeSavedGateway::$keys);
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertNotSame(FakeSavedGateway::$keys[0], FakeSavedGateway::$keys[1]);
    }

    public function test_final_failure_stops_autopay_and_notifies(): void
    {
        $client = $this->client();
        $this->method($client);
        FakeSavedGateway::$results = array_fill(0, 10, ChargeResult::failed('Insufficient funds'));
        $invoice = $this->invoice($client);

        foreach ([1, 2, 2, 3] as $wait) { // days 1, 3, 5 relative to the first attempt
            $this->travel($wait)->days();
            app(AutoPayer::class)->run();
        }
        $this->assertCount(4, FakeSavedGateway::$keys);
        $this->assertSame('final', AutopayAttempt::where('invoice_id', $invoice->id)->orderByDesc('attempt')->first()->status);
        Mail::assertSent(BillingMail::class, fn ($m) => str_contains(implode(' ', $m->lines), 'will not try again automatically'));

        $this->travel(30)->days();
        app(AutoPayer::class)->run();
        $this->assertCount(4, FakeSavedGateway::$keys);
        $this->assertSame(InvoiceStatus::Open, $invoice->refresh()->status);
    }

    public function test_requires_action_pauses_autopay_and_asks_customer_to_pay_manually(): void
    {
        $client = $this->client();
        $this->method($client);
        FakeSavedGateway::$results = [ChargeResult::requiresAction()];
        $invoice = $this->invoice($client);

        $this->assertSame('requires_action', AutopayAttempt::where('invoice_id', $invoice->id)->sole()->status);
        Mail::assertSent(BillingMail::class, fn ($m) => str_contains($m->mailSubject, 'Action needed'));

        $this->travel(5)->days();
        app(AutoPayer::class)->run();
        $this->assertCount(1, FakeSavedGateway::$keys);
    }

    public function test_pending_charge_waits_then_counts_as_failed(): void
    {
        $client = $this->client();
        $this->method($client);
        FakeSavedGateway::$results = [ChargeResult::pending('pi_1')];
        $invoice = $this->invoice($client);

        $this->travel(3)->days();
        app(AutoPayer::class)->run();
        $this->assertCount(1, FakeSavedGateway::$keys);

        $this->travel(3)->days();
        app(AutoPayer::class)->run();
        $this->assertCount(2, FakeSavedGateway::$keys);
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
    }

    public function test_webhook_recording_the_same_reference_is_idempotent(): void
    {
        $client = $this->client();
        $this->method($client);
        $invoice = $this->invoice($client);
        $ref = Payment::where('invoice_id', $invoice->id)->value('reference');

        app(PaymentService::class)->record($invoice, 'fakecard', 2500, $ref);
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
    }

    public function test_command_runs(): void
    {
        $this->artisan('billing:autopay')->assertSuccessful();
    }

    public function test_autopay_can_be_switched_off(): void
    {
        $client = $this->client();
        $this->method($client);
        Settings::set('autopay.enabled', 'false');
        $this->assertSame(InvoiceStatus::Open, $this->invoice($client)->refresh()->status);
    }
}
