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
use App\Models\Ticket;
use App\Models\WebhookDelivery;
use App\Services\TicketService;
use App\Support\Settings;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $currency = (string) Settings::get('billing.currency', config('billing.currency'));
        $net = fn ($q) => (int) (clone $q)->where('type', 'payment')->sum('amount') - (int) (clone $q)->where('type', 'refund')->sum('amount');
        $paid = fn () => Payment::external()->where('currency', $currency)->where('status', PaymentStatus::Completed->value);

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

        // Same window last month (1st .. same day-of-month) so the delta compares like with like.
        $prevStart = $monthStart->copy()->subMonthNoOverflow();
        $prevEnd = $prevStart->copy()->day(min(now()->day, $prevStart->daysInMonth))->endOfDay();
        $revenue = $net($paid()->where('paid_at', '>=', $monthStart));
        $revenuePrev = $net($paid()->whereBetween('paid_at', [$prevStart, $prevEnd]));

        $open = Invoice::external()->where('status', InvoiceStatus::Open->value)->where('currency', $currency);

        return view('admin.dashboard.index', [
            'currency' => $currency,
            'revenue' => $revenue,
            'revenuePrev' => $revenuePrev,
            'outstanding' => (int) (clone $open)->sum('total') - (int) (clone $open)->sum('amount_paid'),
            'outstandingCount' => (clone $open)->count(),
            'overdueCount' => Invoice::external()->overdue()->count(),
            'overdueAmount' => (int) Invoice::external()->overdue()->where('currency', $currency)->sum('total') - (int) Invoice::external()->overdue()->where('currency', $currency)->sum('amount_paid'),
            'activeServices' => Service::external()->where('status', ServiceStatus::Active->value)->count(),
            'newClients' => Client::external()->where('created_at', '>=', now()->subDays(30))->count(),
            'ticketsAwaiting' => TicketService::enabled() ? Ticket::visibleTo($request->user())->whereIn('status', ['open', 'customer_reply'])->count() : null,
            'months' => $months,
            'recentInvoices' => Invoice::with('client')->latest('id')->limit(6)->get(),
            'recentPayments' => Payment::with('client', 'invoice')->latest('id')->limit(6)->get(),
            'attention' => Service::with('client', 'product')->where('status', ServiceStatus::Pending->value)->whereNotNull('provision_error')->latest('id')->limit(8)->get(),
            'failedWebhooks' => WebhookDelivery::with('endpoint')->where('success', false)->where('attempts', '>', 0)->latest('id')->limit(5)->get(),
        ]);
    }
}
