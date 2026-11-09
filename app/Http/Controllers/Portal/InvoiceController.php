<?php

namespace App\Http\Controllers\Portal;

use App\Plugins\GatewayRegistry;
use Illuminate\Http\Request;

class InvoiceController extends PortalController
{
    public function index(Request $request)
    {
        $status = $request->query('status');
        $invoices = $this->client()->invoices()->where('status', '!=', 'draft')
            ->when(in_array($status, ['open', 'paid', 'void', 'uncollectible'], true), fn ($q) => $q->where('status', $status))
            ->latest('id')->paginate(20)->withQueryString();

        return view('portal.invoices.index', ['invoices' => $invoices, 'status' => $status]);
    }

    public function show(GatewayRegistry $gateways, string $invoice)
    {
        $invoice = $this->invoice($invoice)->load('client', 'items', 'payments');

        return view('portal.invoices.show', [
            'invoice' => $invoice,
            'gateways' => $invoice->isPayable() ? $gateways->available($invoice->currency) : [],
            'titles' => collect($gateways->all())->mapWithKeys(fn ($g, $k) => [$k => $gateways->title($k)]),
        ]);
    }
}
