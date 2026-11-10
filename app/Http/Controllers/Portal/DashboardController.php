<?php

namespace App\Http\Controllers\Portal;

use App\Enums\InvoiceStatus;
use App\Enums\QuoteStatus;
use App\Enums\ServiceStatus;

class DashboardController extends PortalController
{
    public function __invoke()
    {
        $client = $this->client();
        $unpaid = $client->invoices()->where('status', InvoiceStatus::Open->value)->orderBy('due_date')->get();

        return view('portal.dashboard', [
            'client' => $client,
            'unpaid' => $unpaid,
            'owing' => $unpaid->groupBy('currency')->map(fn ($g) => $g->sum(fn ($i) => $i->balance())),
            'services' => $client->services()->with('product')->whereIn('status', [ServiceStatus::Active->value, ServiceStatus::Pending->value, ServiceStatus::Suspended->value])->latest()->limit(6)->get(),
            'renewals' => $client->services()->with('product')->where('status', ServiceStatus::Active->value)->where('cancel_at_period_end', false)
                ->whereNotNull('next_due_date')->whereBetween('next_due_date', [today(), today()->addDays(30)])->orderBy('next_due_date')->limit(5)->get(),
            'serviceCount' => $client->services()->where('status', ServiceStatus::Active->value)->count(),
            'quotes' => $client->quotes()->where('status', QuoteStatus::Sent->value)->get()->filter->isOpenForResponse(),
        ]);
    }
}
