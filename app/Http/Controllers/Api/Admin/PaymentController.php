<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends ApiController
{
    public function index(Request $request)
    {
        return $this->paginate($this->filtered($request)->latest('id'), PaymentResource::class, $request);
    }

    /** Completed payments, refunds and the net, per currency, for everything the same filters as the list match. */
    public function summary(Request $request)
    {
        $rows = $this->filtered($request)->external()->where('status', PaymentStatus::Completed->value)->reorder()
            ->selectRaw('currency, type, sum(amount) as total')->groupBy('currency', 'type')->get()->groupBy('currency');
        $m = fn (int $amount, string $c) => ['amount' => $amount, 'currency' => $c, 'formatted' => Money::format($amount, $c)];

        return response()->json(['data' => $rows->map(function ($r, $currency) use ($m) {
            $in = (int) $r->firstWhere('type', 'payment')?->total;
            $out = (int) $r->firstWhere('type', 'refund')?->total;

            return ['currency' => $currency, 'received' => $m($in, $currency), 'refunded' => $m($out, $currency), 'net' => $m($in - $out, $currency)];
        })->values()]);
    }

    public function show(Payment $payment): PaymentResource
    {
        return new PaymentResource($payment);
    }

    /** Refund through the original gateway. Omit amount to refund everything still refundable. */
    public function refund(Request $request, Payment $payment, PaymentService $payments)
    {
        $data = $request->validate(['amount' => ['nullable', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:255']]);
        $amount = $data['amount'] ?? $payment->amount - $payment->refundedAmount();
        $refund = $payments->refund($payment, $amount, $data['reason'] ?? null);
        $this->audit('payment.refunded', $refund, 'Refunded '.Money::format($amount, $payment->currency));

        return (new PaymentResource($refund))->response()->setStatusCode(201);
    }

    private function filtered(Request $request): Builder
    {
        $f = $request->validate([
            'invoice_id' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'integer'],
            'gateway' => ['nullable', 'string', 'max:64'],
            'type' => ['nullable', 'in:payment,refund'],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return Payment::query()
            ->when($f['invoice_id'] ?? null, fn ($q, $v) => $q->where('invoice_id', $v))
            ->when($f['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when($f['gateway'] ?? null, fn ($q, $v) => $q->where('gateway', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('paid_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('paid_at', '<=', $v));
    }
}
