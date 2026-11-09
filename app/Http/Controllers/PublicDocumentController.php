<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Quote;
use App\Plugins\GatewayRegistry;
use App\Services\QuoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Link-shared invoice and quote pages: the URL contains the document's UUID, so no sign-in is needed. */
class PublicDocumentController extends Controller
{
    public function invoice(Invoice $invoice, GatewayRegistry $gateways)
    {
        abort_if($invoice->status->value === 'draft', 404);
        $invoice->load('client', 'items', 'payments');

        return view('public.invoice', ['invoice' => $invoice, 'gateways' => $invoice->isPayable() ? $gateways->available($invoice->currency) : [], 'titles' => collect($gateways->all())->mapWithKeys(fn ($g, $k) => [$k => $gateways->title($k)])]);
    }

    public function quote(Quote $quote)
    {
        abort_if($quote->status->value === 'draft', 404);
        $quote->load('client', 'items');

        return view('public.quote', ['quote' => $quote]);
    }

    public function respond(Request $request, Quote $quote, QuoteService $quotes): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:accept,decline'], 'name' => ['nullable', 'string', 'max:255']]);
        try {
            if ($data['decision'] === 'accept') {
                $request->validate(['name' => ['required', 'string', 'max:255']]);
                $quote = $quotes->accept($quote, $data['name']);
                if ($quote->invoice) {
                    return redirect()->route('invoice.public', $quote->invoice->uuid)->with('status', 'Quote accepted. Your invoice is below.');
                }
                $message = 'Quote accepted. Thank you!';
            } else {
                $quotes->decline($quote);
                $message = 'Quote declined.';
            }
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $message);
    }
}
