<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\AutopayAttemptResource;
use App\Models\AutopayAttempt;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The log of automatic payment attempts (Settings → Billing → Automatic payments are under /settings/autopay). */
class AutopayController extends ApiController
{
    public const STATUSES = ['success', 'failed', 'requires_action', 'pending', 'final'];

    public function attempts(Request $request)
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'invoice_id' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'integer'],
            'gateway' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $query = AutopayAttempt::query()
            ->leftJoin('invoices', 'invoices.id', '=', 'autopay_attempts.invoice_id')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'autopay_attempts.payment_method_id')
            ->select('autopay_attempts.*', 'invoices.client_id as client_id', 'invoices.number as invoice_number', 'invoices.uuid as invoice_uuid', 'payment_methods.uuid as payment_method_uuid')
            ->orderByDesc('autopay_attempts.id')
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('autopay_attempts.status', $v))
            ->when($f['invoice_id'] ?? null, fn ($q, $v) => $q->where('autopay_attempts.invoice_id', $v))
            ->when($f['client_id'] ?? null, fn ($q, $v) => $q->where('invoices.client_id', $v))
            ->when($f['gateway'] ?? null, fn ($q, $v) => $q->where('autopay_attempts.gateway', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('autopay_attempts.created_at', '>=', $v.' 00:00:00'))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('autopay_attempts.created_at', '<=', $v.' 23:59:59'));

        return $this->paginate($query, AutopayAttemptResource::class, $request);
    }
}
