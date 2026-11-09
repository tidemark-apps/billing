<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Portal\PortalController;
use Illuminate\Http\Request;

/** Client-area PDF downloads, scoped to the signed-in client (anything else is a 404; drafts are hidden). */
class PortalPdfController extends PortalController
{
    use RespondsWithPdf;

    public function invoicePdf(Request $request, string $invoice)
    {
        return $this->pdfResponse($request, $this->invoice($invoice)->load('client', 'items', 'payments'));
    }

    public function quotePdf(Request $request, string $quote)
    {
        return $this->pdfResponse($request, $this->quote($quote)->load('client', 'items'));
    }
}
