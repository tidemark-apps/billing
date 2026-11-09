<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Pdf\RespondsWithPdf;
use App\Models\Invoice;
use App\Models\Quote;
use Illuminate\Http\Request;

/** PDF downloads for staff, drafts included (the same documents as the admin screens). */
class PdfController extends ApiController
{
    use RespondsWithPdf;

    public function invoice(Request $request, Invoice $invoice)
    {
        return $this->pdfResponse($request, $invoice->load('client', 'items', 'payments'));
    }

    public function quote(Request $request, Quote $quote)
    {
        return $this->pdfResponse($request, $quote->load('client', 'items'));
    }
}
