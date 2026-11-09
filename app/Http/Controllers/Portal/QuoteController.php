<?php

namespace App\Http\Controllers\Portal;

use App\Services\QuoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class QuoteController extends PortalController
{
    public function index()
    {
        return view('portal.quotes.index', ['quotes' => $this->client()->quotes()->where('status', '!=', 'draft')->latest('id')->paginate(20)]);
    }

    public function show(string $quote)
    {
        return view('portal.quotes.show', ['quote' => $this->quote($quote)->load('client', 'items', 'invoice')]);
    }

    public function respond(Request $request, QuoteService $quotes, string $quote): RedirectResponse
    {
        $quote = $this->quote($quote);
        $data = $request->validate(['decision' => ['required', 'in:accept,decline'], 'name' => ['nullable', 'string', 'max:255']]);
        try {
            if ($data['decision'] === 'accept') {
                $request->validate(['name' => ['required', 'string', 'max:255']]);
                $quote = $quotes->accept($quote, $data['name']);
                if ($quote->invoice) {
                    return redirect()->route('portal.invoices.show', $quote->invoice)->with('status', 'Quote accepted. Your invoice is below.');
                }

                return back()->with('status', 'Quote accepted. Thank you!');
            }
            $quotes->decline($quote);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Quote declined.');
    }
}
