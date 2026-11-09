<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Pdf\RespondsWithPdf;
use Illuminate\Http\Request;

/** PDF downloads of the token owner's own invoices and quotes (drafts are hidden, other clients' documents are a 404). */
class PdfController extends ApiController
{
    use RespondsWithPdf;

    public function invoice(Request $request, string $invoice)
    {
        $model = $this->client($request)->invoices()->where('status', '!=', 'draft')->where('uuid', $invoice)->firstOrFail();

        return $this->pdfResponse($request, $model->load('client', 'items', 'payments'));
    }

    public function quote(Request $request, string $quote)
    {
        $model = $this->client($request)->quotes()->where('status', '!=', 'draft')->where('uuid', $quote)->firstOrFail();

        return $this->pdfResponse($request, $model->load('client', 'items'));
    }
}
