<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\ServiceResource;
use App\Http\Resources\WebhookDeliveryResource;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\WebhookDelivery;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\Request;

/** The numbers on the admin dashboard, in the store currency. */
class DashboardController extends ApiController
{
    public function __invoke(Request $request)
    {
        $currency = (string) Settings::get('billing.currency', config('billing.currency'));
        $money = fn (int $amount) => ['amount' => $amount, 'currency' => $currency, 'formatted' => Money::format($amount, $currency)];
        $paid = fn () => Payment::where('currency', $currency)->where('status', PaymentStatus::Completed->value);
        $net = fn ($rows) => (int) $rows->sum(fn ($p) => $p->type === 'refund' ? -$p->amount : $p->amount);

        $from = now()->startOfMonth()->subMonths(11);
        $rows = $paid()->where('paid_at', '>=', $from)->get(['type', 'amount', 'paid_at']);
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $from->copy()->addMonths($i);
            $months[] = ['month' => $m->format('Y-m'), 'revenue' => $money(max(0, $net($rows->filter(fn ($p) => $p->paid_at && $p->paid_at->format('Y-m') === $m->format('Y-m')))))];
        }

        $open = Invoice::where('status', InvoiceStatus::Open->value)->where('currency', $currency);

        return response()->json(['data' => [
            'currency' => $currency,
            'revenue_this_month' => $money($net($rows->filter(fn ($p) => $p->paid_at && $p->paid_at->gte(now()->startOfMonth())))),
            'outstanding' => $money((int) (clone $open)->sum('total') - (int) (clone $open)->sum('amount_paid')),
            'overdue_invoices' => Invoice::overdue()->count(),
            'active_services' => Service::where('status', ServiceStatus::Active->value)->count(),
            'new_clients_30d' => Client::where('created_at', '>=', now()->subDays(30))->count(),
            'monthly_revenue' => $months,
            'recent_invoices' => InvoiceResource::collection(Invoice::latest('id')->limit(6)->get())->resolve($request),
            'recent_payments' => PaymentResource::collection(Payment::latest('id')->limit(6)->get())->resolve($request),
            'attention_services' => ServiceResource::collection(Service::where('status', ServiceStatus::Pending->value)->whereNotNull('provision_error')->latest('id')->limit(8)->get())->resolve($request),
            'failed_webhooks' => WebhookDeliveryResource::collection(WebhookDelivery::where('success', false)->where('attempts', '>', 0)->latest('id')->limit(5)->get())->resolve($request),
        ]]);
    }
}
