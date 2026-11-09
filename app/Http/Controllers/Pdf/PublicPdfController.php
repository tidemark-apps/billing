<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Quote;
use Illuminate\Http\Request;

/** Link-shared PDF downloads: the unguessable UUID in the URL is the credential. Drafts stay hidden. */
class PublicPdfController extends Controller
{
    use RespondsWithPdf;

    public function invoice(Request $request, Invoice $invoice)
    {
        abort_if($invoice->status->value === 'draft', 404);

        return $this->pdfResponse($request, $invoice->load('client', 'items', 'payments'));
    }

    public function quote(Request $request, Quote $quote)
    {
        abort_if($quote->status->value === 'draft', 404);

        return $this->pdfResponse($request, $quote->load('client', 'items'));
    }
}
