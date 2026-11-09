<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Quote;
use Illuminate\Http\Request;

/** Staff PDF downloads. Mounted inside the staff-only admin route group. */
class AdminPdfController extends Controller
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
