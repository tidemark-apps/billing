<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Support\Activity;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'gateway' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'type' => ['nullable', 'in:payment,refund'],
            'client_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $query = Payment::with('client', 'invoice')
            ->when($filters['gateway'] ?? null, fn ($q, $v) => $q->where('gateway', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('paid_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('paid_at', '<=', $v));

        // Net of completed payments minus completed refunds, per currency, for everything the filters match.
        $totals = (clone $query)->where('status', PaymentStatus::Completed->value)->reorder()
            ->selectRaw('currency, type, sum(amount) as total')->groupBy('currency', 'type')->get()
            ->groupBy('currency')->map(fn ($rows) => [
                'in' => (int) $rows->firstWhere('type', 'payment')?->total,
                'out' => (int) $rows->firstWhere('type', 'refund')?->total,
            ]);

        return view('admin.payments.index', [
            'payments' => $query->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'totals' => $totals,
            'gateways' => Payment::query()->distinct()->orderBy('gateway')->pluck('gateway'),
            'clients' => Client::orderBy('name')->get(['id', 'name', 'company_name']),
        ]);
    }

    public function refund(Request $request, Payment $payment, PaymentService $payments): RedirectResponse
    {
        $data = $request->validate(['amount' => ['nullable', 'numeric', 'gt:0'], 'reason' => ['nullable', 'string', 'max:255']]);
        $remaining = $payment->amount - $payment->refundedAmount();
        $amount = isset($data['amount']) ? Money::toMinor($data['amount'], $payment->currency) : $remaining;

        try {
            $refund = $payments->refund($payment, $amount, $data['reason'] ?? null);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
        Activity::log('payment.refunded', $refund, 'Refunded '.money($amount, $payment->currency));

        return back()->with('status', 'Refunded '.money($amount, $payment->currency).'.');
    }
}
