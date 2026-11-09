<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\AutopayAttempt;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Support\Activity;
use App\Support\ChargeResult;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * Automatic payment of invoices with a client's saved payment method.
 *
 * Settings (Settings → Billing → Automatic payments):
 *   autopay.enabled              default on
 *   autopay.days_before_due      default 0: first charge on the due date (N charges N days earlier)
 *   autopay.renewals_at_creation default off: charge renewal invoices as soon as they are issued instead
 *   autopay.retry_days           default "1,3,5": retries this many days after the FIRST attempt
 *   autopay.max_attempts         default 4: first attempt + 3 retries (never more than retry days + 1)
 *
 * Each attempt is a row in autopay_attempts. A success, a requires_action (customer must authenticate: we email
 * them to pay manually) or a final failure ends autopay for that invoice. A "pending" charge (bank debit) waits
 * for the gateway webhook for up to 5 days before counting as failed.
 */
class AutoPayer
{
    private const PENDING_DAYS = 5;

    public function __construct(private readonly PaymentMethods $methods, private readonly Notifier $notifier) {}

    public function enabled(): bool
    {
        return Settings::bool('autopay.enabled', true);
    }

    /** InvoiceCreated listener entry: charge now if the schedule says it is already time. */
    public function handleIssued(Invoice $invoice): void
    {
        if (! $this->enabled() || $invoice->status !== InvoiceStatus::Open || $invoice->balance() < 1) {
            return;
        }
        if ($this->shouldAttempt($invoice)) {
            $this->attempt($invoice);
        }
    }

    /** Scheduler entry (billing:autopay): attempt every open invoice whose first charge or retry is due. @return array{checked: int, paid: int, failed: int, action_required: int, pending: int} */
    public function run(): array
    {
        $out = ['checked' => 0, 'paid' => 0, 'failed' => 0, 'action_required' => 0, 'pending' => 0];
        if (! $this->enabled()) {
            return $out;
        }
        $clients = PaymentMethod::active()->where('autopay', true)->select('client_id');
        Invoice::where('status', InvoiceStatus::Open->value)->whereIn('client_id', $clients)->orderBy('id')->chunkById(100, function ($invoices) use (&$out) {
            foreach ($invoices as $invoice) {
                $out['checked']++;
                if (! $this->shouldAttempt($invoice)) {
                    continue;
                }
                match ($this->attempt($invoice)) {
                    ChargeResult::SUCCESS => $out['paid']++,
                    ChargeResult::FAILED => $out['failed']++,
                    ChargeResult::REQUIRES_ACTION => $out['action_required']++,
                    ChargeResult::PENDING => $out['pending']++,
                    default => null,
                };
            }
        });

        return $out;
    }

    /** @return array<int, int> retry offsets in days after the first attempt */
    public function retryDays(): array
    {
        $days = array_map('intval', array_filter(array_map('trim', explode(',', (string) Settings::get('autopay.retry_days', '1,3,5'))), 'strlen'));
        $days = array_values(array_filter($days, fn ($d) => $d > 0));
        sort($days);

        return $days;
    }

    public function maxAttempts(): int
    {
        return max(1, min((int) Settings::get('autopay.max_attempts', 4), count($this->retryDays()) + 1));
    }

    /** Is a charge due for this invoice right now? */
    public function shouldAttempt(Invoice $invoice): bool
    {
        if (! $this->enabled() || ! $invoice->isPayable()) {
            return false;
        }
        $attempts = AutopayAttempt::where('invoice_id', $invoice->id)->orderBy('attempt')->get();
        if ($attempts->contains(fn ($a) => in_array($a->status, ['success', 'final', 'requires_action'], true))) {
            return false;
        }
        if ($attempts->isEmpty()) {
            if (Settings::bool('autopay.renewals_at_creation') && $invoice->items()->where('kind', 'renewal')->exists()) {
                return true;
            }
            $due = $invoice->due_date?->copy()->startOfDay();

            return $due !== null && now()->gte($due->subDays(max(0, (int) Settings::get('autopay.days_before_due', 0))));
        }
        $last = $attempts->last();
        if ($last->status === 'pending') {
            if ($last->created_at->gt(now()->subDays(self::PENDING_DAYS))) {
                return false;
            }
            $last->update(['status' => 'failed', 'message' => trim($last->message.' Not confirmed by the provider in time.')]);
        }
        $failed = $attempts->where('status', 'failed')->count();
        $offset = $this->retryDays()[$failed - 1] ?? null;
        if ($failed >= $this->maxAttempts() || $offset === null) {
            return false;
        }

        return now()->gte($attempts->first()->created_at->copy()->startOfDay()->addDays($offset));
    }

    /** The method autopay would use: the default autopay method first, then other autopay methods. */
    public function methodFor(Invoice $invoice): ?PaymentMethod
    {
        return $this->methods->usableFor($invoice)->filter(fn ($m) => $m->autopay)->first();
    }

    /** Charge now. Returns the ChargeResult status, or null when nothing was attempted. */
    public function attempt(Invoice $invoice): ?string
    {
        $lock = Cache::lock('autopay.invoice.'.$invoice->id, 120);
        if (! $lock->get()) {
            return null;
        }
        try {
            $invoice->refresh()->loadMissing('client');
            if (! $this->shouldAttempt($invoice) || ! ($method = $this->methodFor($invoice))) {
                return null;
            }
            $n = AutopayAttempt::where('invoice_id', $invoice->id)->count() + 1;
            try {
                $row = AutopayAttempt::create(['invoice_id' => $invoice->id, 'payment_method_id' => $method->id, 'attempt' => $n, 'status' => 'pending', 'gateway' => $method->gateway]);
            } catch (QueryException) {
                return null; // another worker took this attempt number
            }

            $result = $this->methods->pay($method, $invoice, 'autopay-'.$invoice->uuid.'-'.$n);

            return $this->settle($row, $invoice, $method, $result, $n);
        } finally {
            $lock->release();
        }
    }

    private function settle(AutopayAttempt $row, Invoice $invoice, PaymentMethod $method, ChargeResult $result, int $n): string
    {
        $props = ['invoice' => $invoice->displayNumber(), 'attempt' => $n, 'method' => $method->displayName()];
        $amount = Money::format($invoice->balance() ?: $invoice->total, $invoice->currency);

        switch ($result->status) {
            case ChargeResult::SUCCESS:
                $row->update(['status' => 'success', 'reference' => $result->reference]);
                Activity::log('autopay.paid', $invoice, "Autopay charged {$method->displayName()} for invoice {$invoice->displayNumber()}", $props);
                break;
            case ChargeResult::PENDING:
                $row->update(['status' => 'pending', 'reference' => $result->reference, 'message' => $result->message ?: null]);
                Activity::log('autopay.pending', $invoice, "Autopay charge for invoice {$invoice->displayNumber()} is processing", $props);
                break;
            case ChargeResult::REQUIRES_ACTION:
                $row->update(['status' => 'requires_action', 'reference' => $result->reference, 'message' => $result->message]);
                Activity::log('autopay.requires_action', $invoice, "Autopay for invoice {$invoice->displayNumber()} needs the customer to authenticate", $props);
                $this->email($invoice, 'Action needed: pay invoice '.$invoice->displayNumber(), 'Please complete your payment', [
                    "We tried to charge {$method->displayName()} automatically for invoice {$invoice->displayNumber()} ($amount), but your bank needs you to confirm the payment.",
                    'Automatic payment is paused for this invoice. Please pay it yourself using the button below.',
                ], 'Pay invoice');
                break;
            default:
                $final = $n >= $this->maxAttempts();
                $row->update(['status' => $final ? 'final' : 'failed', 'message' => $result->message]);
                $props['reason'] = $result->message;
                Activity::log($final ? 'autopay.failed_final' : 'autopay.failed', $invoice, "Autopay failed for invoice {$invoice->displayNumber()}: {$result->message}", $props);
                if ($final) {
                    $this->email($invoice, 'Payment failed for invoice '.$invoice->displayNumber(), 'We could not collect your payment', [
                        "We could not charge {$method->displayName()} for invoice {$invoice->displayNumber()} ($amount) after $n attempts.",
                        'Reason: '.($result->message ?: 'declined'),
                        'We will not try again automatically. Please update your payment method or pay the invoice yourself.',
                    ], 'Pay invoice');
                    $this->notifier->send($this->notifier->adminAddress(), "Autopay gave up on invoice {$invoice->displayNumber()}", 'Autopay failed', [
                        "{$invoice->client->displayName()}: invoice {$invoice->displayNumber()} ($amount) failed after $n attempts. Reason: ".($result->message ?: 'declined'),
                    ]);
                } else {
                    $next = $this->retryDays()[$n - 1] ?? null;
                    $this->email($invoice, 'Payment failed for invoice '.$invoice->displayNumber(), 'We could not collect your payment', array_filter([
                        "We tried to charge {$method->displayName()} for invoice {$invoice->displayNumber()} ($amount) and it was declined.",
                        'Reason: '.($result->message ?: 'declined'),
                        $next !== null ? 'We will try again automatically. You can also pay the invoice yourself or update your payment method.' : null,
                    ]), 'Pay invoice');
                }
        }

        return $result->status;
    }

    /** @param  array<int, string>  $lines */
    private function email(Invoice $invoice, string $subject, string $heading, array $lines, string $label): void
    {
        $url = Route::has('invoice.public') ? route('invoice.public', $invoice->uuid) : url('/');
        $this->notifier->send($invoice->client->email, $subject, $heading, $lines, $label, $url);
    }
}
