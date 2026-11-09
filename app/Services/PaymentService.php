<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Events\PaymentReceived;
use App\Events\PaymentRefunded;
use App\Models\Invoice;
use App\Models\Payment;
use App\Plugins\GatewayRegistry;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(private readonly InvoiceService $invoices, private readonly GatewayRegistry $gateways, private readonly Notifier $notifier) {}

    /**
     * Record money received against an invoice. Safe to call twice for the same (gateway, reference): the
     * second call returns the first payment, which is what makes webhook + browser-return handling idempotent.
     *
     * @param  array{fee?: int, notes?: string, meta?: array<string, mixed>, paid_at?: \DateTimeInterface}  $options
     */
    public function record(Invoice $invoice, string $gateway, int $amount, ?string $reference = null, array $options = []): Payment
    {
        if ($reference !== null) {
            $existing = Payment::where(['gateway' => $gateway, 'reference' => $reference, 'type' => 'payment'])->first();
            if ($existing) {
                return $existing;
            }
        }

        $payment = DB::transaction(function () use ($invoice, $gateway, $amount, $reference, $options) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (in_array($invoice->status, [InvoiceStatus::Void, InvoiceStatus::Draft], true)) {
                throw new \DomainException('This invoice cannot accept payments.');
            }
            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'client_id' => $invoice->client_id,
                'gateway' => $gateway,
                'reference' => $reference,
                'type' => 'payment',
                'status' => PaymentStatus::Completed,
                'currency' => $invoice->currency,
                'amount' => $amount,
                'fee' => $options['fee'] ?? 0,
                'paid_at' => $options['paid_at'] ?? now(),
                'notes' => $options['notes'] ?? null,
                'meta' => $options['meta'] ?? null,
            ]);
            $this->syncInvoice($invoice);

            return $payment;
        });

        $payment->refresh();
        event(new PaymentReceived($payment));
        $this->notifier->paymentReceived($payment);
        $this->finalize($payment->invoice);

        return $payment;
    }

    /** An admin recorded a bank transfer, cheque or cash payment. */
    public function recordManual(Invoice $invoice, int $amount, string $method = 'manual', ?string $notes = null): Payment
    {
        return $this->record($invoice, 'manual', $amount, null, ['notes' => trim($method.($notes ? ': '.$notes : ''))]);
    }

    /** Refund through the original gateway (or just record it for manual payments). */
    public function refund(Payment $payment, int $amount, ?string $reason = null): Payment
    {
        if ($payment->isRefund() || $payment->status !== PaymentStatus::Completed) {
            throw new \DomainException('Only completed payments can be refunded.');
        }
        $refundable = $payment->amount - $payment->refundedAmount();
        if ($amount < 1 || $amount > $refundable) {
            throw new \DomainException('The refund amount must be between 1 and the amount still refundable.');
        }
        $reference = null;
        if ($payment->gateway !== 'manual') {
            $gateway = $this->gateways->get($payment->gateway) ?? throw new \DomainException("Gateway [{$payment->gateway}] is not available.");
            $result = $gateway->refund($payment, $amount, $reason);
            if (! $result->success) {
                throw new \DomainException($result->message ?: 'The gateway refused the refund.');
            }
            $reference = $result->reference;
        }

        return $this->recordRefund($payment, $amount, $reference, $reason);
    }

    /** Record a refund that already happened at the gateway (webhook path). Idempotent on (gateway, reference). */
    public function recordRefund(Payment $original, int $amount, ?string $reference = null, ?string $notes = null): Payment
    {
        if ($reference !== null) {
            $existing = Payment::where(['gateway' => $original->gateway, 'reference' => $reference, 'type' => 'refund'])->first();
            if ($existing) {
                return $existing;
            }
        }
        $refund = DB::transaction(function () use ($original, $amount, $reference, $notes) {
            $refund = Payment::create([
                'invoice_id' => $original->invoice_id,
                'client_id' => $original->client_id,
                'refund_of_id' => $original->id,
                'gateway' => $original->gateway,
                'reference' => $reference,
                'type' => 'refund',
                'status' => PaymentStatus::Completed,
                'currency' => $original->currency,
                'amount' => $amount,
                'paid_at' => now(),
                'notes' => $notes,
            ]);
            if ($original->invoice) {
                $this->syncInvoice($original->invoice);
            }

            return $refund;
        });
        event(new PaymentRefunded($refund));

        return $refund;
    }

    /** Net received = completed payments − completed refunds. */
    private function syncInvoice(Invoice $invoice): void
    {
        $paid = (int) Payment::where('invoice_id', $invoice->id)->where('status', PaymentStatus::Completed->value)->where('type', 'payment')->sum('amount');
        $refunded = (int) Payment::where('invoice_id', $invoice->id)->where('status', PaymentStatus::Completed->value)->where('type', 'refund')->sum('amount');
        $invoice->update(['amount_paid' => $paid - $refunded]);
    }

    private function finalize(?Invoice $invoice): void
    {
        $invoice?->refresh();
        if ($invoice && $invoice->status === InvoiceStatus::Open && $invoice->amount_paid >= $invoice->total) {
            $this->invoices->markPaid($invoice);
        }
    }
}
