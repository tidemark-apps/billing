<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Service;
use App\Support\Activity;
use App\Support\Settings;

/**
 * How services get cancelled (Settings → Billing → Cancellations).
 *
 *  - mode: "end_of_term" (customers always cancel at the end of the billing term), "immediate" (always right away) or
 *    "customer_choice" (the customer picks). Staff are never restricted.
 *  - refund: when a service is cancelled immediately, the unused part of the period it was paid for is refunded by the day,
 *    through the payment(s) that covered it.
 */
class Cancellations
{
    public const MODES = [
        'end_of_term' => 'At the end of the billing term',
        'immediate' => 'Immediately',
        'customer_choice' => 'Let the customer choose',
    ];

    public function __construct(private readonly ProvisioningService $provisioning, private readonly PaymentService $payments) {}

    public static function mode(): string
    {
        $mode = (string) Settings::get('billing.cancel_mode', 'end_of_term');

        return array_key_exists($mode, self::MODES) ? $mode : 'end_of_term';
    }

    public static function customerChooses(): bool
    {
        return self::mode() === 'customer_choice';
    }

    public static function refundsByDefault(): bool
    {
        return Settings::bool('billing.cancel_refund', false);
    }

    /** Whether a customer's cancellation takes effect now, given what they asked for ("now" / "end" / null). */
    public static function customerCancelsNow(?string $asked): bool
    {
        return match (self::mode()) {
            'immediate' => true,
            'customer_choice' => $asked === 'now',
            default => false,
        };
    }

    /**
     * What an immediate cancellation would refund today: the unused days of the period the latest paid invoice covered, at
     * the price paid (tax included), capped by what the payments on that invoice still allow.
     *
     * @return array{amount: int, currency: string, remaining_days: int, period_days: int, invoice_id: int}|null
     */
    public function refundPreview(Service $service): ?array
    {
        if (! $service->next_due_date || ! $service->billing_cycle->isRecurring() || $service->onTrial()) {
            return null;
        }
        $item = InvoiceItem::where('service_id', $service->id)->whereIn('kind', ['product', 'renewal'])
            ->whereHas('invoice', fn ($q) => $q->where('status', InvoiceStatus::Paid->value))
            ->with('invoice.payments')->orderByDesc('invoice_id')->first();
        if (! $item || $item->total <= 0) {
            return null;
        }
        $end = $service->next_due_date->copy()->startOfDay();
        $start = $end->copy()->subMonthsNoOverflow($service->billing_cycle->months());
        $periodDays = max(1, (int) $start->diffInDays($end));
        $remaining = (int) max(0, min($periodDays, now()->startOfDay()->diffInDays($end, false)));
        $paid = (int) round($item->total * ($item->taxable ? 1 + ((float) $item->invoice->tax_rate) / 100 : 1));
        $refundable = $item->invoice->payments->filter(fn ($p) => ! $p->isRefund() && $p->status->value === 'completed')->sum(fn ($p) => $p->amount - $p->refundedAmount());
        $amount = min((int) round($paid * $remaining / $periodDays), (int) $refundable);

        return $amount > 0 ? ['amount' => $amount, 'currency' => $item->invoice->currency, 'remaining_days' => $remaining, 'period_days' => $periodDays, 'invoice_id' => $item->invoice_id] : null;
    }

    /**
     * Cancel a service and, if asked, refund the unused time when it ended straight away.
     *
     * @return array{service: Service, refund: array{amount: int, currency: string, status: string, message: ?string}|null}
     */
    public function cancel(Service $service, bool $immediately, ?string $reason = null, ?bool $refund = null): array
    {
        $wantsRefund = $refund ?? self::refundsByDefault();
        $preview = $immediately && $wantsRefund && $service->status === ServiceStatus::Active ? $this->refundPreview($service) : null;
        $service = $this->provisioning->cancel($service, $immediately, $reason);
        if (! $preview || $service->status !== ServiceStatus::Cancelled) {
            return ['service' => $service, 'refund' => null];
        }

        return ['service' => $service, 'refund' => $this->refund($service, $preview)];
    }

    /** @param  array{amount: int, currency: string, invoice_id: int}  $preview */
    private function refund(Service $service, array $preview): array
    {
        $left = $preview['amount'];
        $payments = Payment::where('invoice_id', $preview['invoice_id'])->where('type', 'payment')->where('status', 'completed')->orderByDesc('id')->get();
        try {
            foreach ($payments as $payment) {
                $take = min($left, $payment->amount - $payment->refundedAmount());
                if ($take > 0) {
                    $this->payments->refund($payment, $take, 'Unused time on '.$service->title().' after cancellation');
                    $left -= $take;
                }
                if ($left <= 0) {
                    break;
                }
            }
            $result = ['amount' => $preview['amount'], 'currency' => $preview['currency'], 'status' => 'issued', 'message' => null];
            Activity::log('service.cancel_refunded', $service, 'Refunded '.money($preview['amount'], $preview['currency']).' for unused time');
        } catch (\DomainException $e) {
            $done = $preview['amount'] - $left;
            $result = ['amount' => $done, 'currency' => $preview['currency'], 'status' => 'failed', 'message' => $e->getMessage()];
            Activity::log('service.cancel_refund_failed', $service, 'The automatic refund could not be completed: '.$e->getMessage());
        }

        return $result;
    }

    /** One sentence for a flash message or API consumer. */
    public static function describe(?array $refund): string
    {
        return match ($refund['status'] ?? null) {
            'issued' => ' '.money($refund['amount'], $refund['currency']).' is being refunded for the unused time.',
            'failed' => ' We could not refund the unused time automatically, so we will do it by hand.',
            default => '',
        };
    }
}
