<?php

namespace App\Http\Controllers\Pdf;

use App\Models\Invoice;
use App\Models\Quote;
use App\Services\PdfRenderer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

trait RespondsWithPdf
{
    protected function pdfResponse(Request $request, Invoice|Quote $document): Response
    {
        $renderer = app(PdfRenderer::class);
        try {
            $bytes = $document instanceof Invoice ? $renderer->invoice($document) : $renderer->quote($document);
        } catch (\Throwable $e) {
            report($e);
            abort(500, 'The PDF could not be generated. Please try again or use the print button.');
        }
        $name = $renderer->filename($document);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('inline') ? 'inline' : 'attachment').'; filename="'.$name.'"',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
