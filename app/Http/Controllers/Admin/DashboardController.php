<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\WebhookDelivery;
use App\Support\Settings;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $currency = (string) Settings::get('billing.currency', config('billing.currency'));
        $net = fn ($q) => (int) (clone $q)->where('type', 'payment')->sum('amount') - (int) (clone $q)->where('type', 'refund')->sum('amount');
        $paid = fn () => Payment::where('currency', $currency)->where('status', PaymentStatus::Completed->value);

        $monthStart = now()->startOfMonth();
        $months = [];
        $from = now()->startOfMonth()->subMonths(11);
        $rows = $paid()->where('paid_at', '>=', $from)->get(['type', 'amount', 'paid_at']);
        for ($i = 0; $i < 12; $i++) {
            $m = $from->copy()->addMonths($i);
            $sum = $rows->filter(fn ($p) => $p->paid_at && $p->paid_at->format('Y-m') === $m->format('Y-m'))
                ->sum(fn ($p) => $p->type === 'refund' ? -$p->amount : $p->amount);
            $months[] = ['label' => $m->format('M'), 'value' => max(0, (int) $sum)];
        }

        $open = Invoice::where('status', InvoiceStatus::Open->value)->where('currency', $currency);

        return view('admin.dashboard.index', [
            'currency' => $currency,
            'revenue' => $net($paid()->where('paid_at', '>=', $monthStart)),
            'outstanding' => (int) (clone $open)->sum('total') - (int) (clone $open)->sum('amount_paid'),
            'overdueCount' => Invoice::overdue()->count(),
            'activeServices' => Service::where('status', ServiceStatus::Active->value)->count(),
            'newClients' => Client::where('created_at', '>=', now()->subDays(30))->count(),
            'months' => $months,
            'recentInvoices' => Invoice::with('client')->latest('id')->limit(6)->get(),
            'recentPayments' => Payment::with('client', 'invoice')->latest('id')->limit(6)->get(),
            'attention' => Service::with('client', 'product')->where('status', ServiceStatus::Pending->value)->whereNotNull('provision_error')->latest('id')->limit(8)->get(),
            'failedWebhooks' => WebhookDelivery::with('endpoint')->where('success', false)->where('attempts', '>', 0)->latest('id')->limit(5)->get(),
        ]);
    }
}
