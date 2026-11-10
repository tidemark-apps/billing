<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Quote;
use App\Plugins\Hooks;
use App\Support\Brand;
use App\Support\Settings;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * Renders invoices and quotes to PDF with dompdf (pure PHP, no binaries, fine on shared hosting).
 *
 * Plugins can change the data handed to the templates through the `pdf.data` filter:
 *   Hooks::filter('pdf.data', fn (array $data, string $type, Model $document) => $data + ['extra' => '…']);
 */
class PdfRenderer
{
    /** Countries that print on US Letter by default. Everyone else gets A4. */
    private const LETTER_COUNTRIES = ['US', 'CA', 'MX', 'CL', 'CO', 'VE', 'PH', 'CR', 'GT', 'PA', 'DO'];

    private const MAX_LOGO_BYTES = 1_000_000;

    public function __construct(private readonly PdfFonts $fonts) {}

    /**
     * True when this document has Chinese, Japanese or Korean text but no CJK font pack is installed
     * (the PDF would show empty boxes). Used for the hint on the admin invoice and quote pages.
     */
    public function needsCjkFonts(Invoice|Quote $document): bool
    {
        if ($this->fonts->installed()) {
            return false;
        }
        $document->loadMissing('client', 'items');
        $client = $document->client;
        $texts = [
            Settings::get('company.name'), Settings::get('company.address'), Settings::get('billing.payment_instructions'),
            $client?->displayName(), ...($client ? $client->addressLines() : []),
            $document->notes, $document->terms ?? null, $document->title ?? null,
            ...$document->items->pluck('description')->all(),
        ];

        return $this->fonts->anyCjk($texts);
    }

    public function invoice(Invoice $invoice): string
    {
        $invoice->loadMissing('client', 'items', 'payments');
        $data = $this->data('invoice', $invoice, $invoice->client->country);

        return $this->toPdf(View::make('pdf.invoice', $data)->render(), $data['paper']);
    }

    public function quote(Quote $quote): string
    {
        $quote->loadMissing('client', 'items');
        $data = $this->data('quote', $quote, $quote->client->country);

        return $this->toPdf(View::make('pdf.quote', $data)->render(), $data['paper']);
    }

    /** A safe download name such as INV-00012.pdf. */
    public function filename(Invoice|Quote $document): string
    {
        $base = $document->number ?: ($document instanceof Invoice ? 'Draft-' : 'Quote-Draft-').$document->id;
        $clean = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $base), '-.');

        return ($clean !== '' ? $clean : 'document').'.pdf';
    }

    public function paper(?string $country = null): string
    {
        $setting = strtolower((string) Settings::get('billing.paper', ''));
        if (in_array($setting, ['a4', 'letter'], true)) {
            return $setting;
        }

        return in_array(strtoupper((string) $country), self::LETTER_COUNTRIES, true) ? 'letter' : 'a4';
    }

    /** @return array<string, mixed> */
    private function data(string $type, Invoice|Quote $document, ?string $country): array
    {
        $company = (string) (Settings::get('company.name') ?: config('billing.name'));
        $data = [
            $type => $document,
            'type' => $type,
            'paper' => $this->paper($country),
            'company' => [
                'name' => $company,
                'address' => (string) Settings::get('company.address', ''),
                'email' => (string) Settings::get('company.email', ''),
                'tax_id' => (string) Settings::get('company.tax_id', ''),
                'logo' => $this->logoDataUri(),
            ],
            'accent' => Brand::light()['ink'],
            'paymentInstructions' => (string) Settings::get('billing.payment_instructions', ''),
            'payUrl' => null,
        ];
        if ($document instanceof Invoice && $document->isPayable()) {
            $data['payUrl'] = route('invoice.public', $document->uuid);
        }

        $filtered = Hooks::apply('pdf.data', $data, $type, $document);

        return is_array($filtered) ? $filtered : $data;
    }

    private function toPdf(string $html, string $paper): string
    {
        $options = new Options;
        $dir = $this->workDir();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setIsFontSubsettingEnabled(true);
        $options->setChroot([base_path()]);
        $options->setAllowedProtocols(['data://' => ['rules' => []]]);
        $options->setTempDir($dir.'/tmp');
        $options->setFontDir($dir.'/fonts');
        $options->setFontCache($dir.'/fonts');
        $options->setLogOutputFile($dir.'/log.htm');
        $options->setDefaultFont('DejaVu Sans');
        $options->setDpi(96);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper($paper === 'letter' ? 'letter' : 'a4', 'portrait');
        $withFonts = $this->fonts->apply($html);
        if ($withFonts !== $html && ! $this->allowMoreMemory()) {
            $withFonts = $html; // better empty boxes than an out-of-memory crash
        }
        $dompdf->loadHtml($withFonts, 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Subsetting a CJK font (5 to 10 MB, up to 30,000 glyphs) takes about 75 MB on top of the app. Raises a low
     * memory_limit where the host allows it; false when it stays too low to try.
     */
    private function allowMoreMemory(): bool
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '-1') {
            return true;
        }
        $toBytes = function (string $v): int {
            $n = (int) $v;

            return $n * match (strtolower(substr($v, -1))) {
                'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1
            };
        };
        if ($toBytes($limit) < 512 * 1048576) {
            @ini_set('memory_limit', '512M');
        }
        if ($toBytes((string) ini_get('memory_limit')) >= 192 * 1048576) {
            return true;
        }
        Log::warning('Not enough PHP memory (memory_limit '.ini_get('memory_limit').') to embed a CJK font in a PDF; raise it to 256M or more. Rendering without CJK fonts.');

        return false;
    }

    /** storage/app/dompdf with its tmp and fonts folders, created on demand. */
    private function workDir(): string
    {
        $dir = rtrim((string) config('pdf-fonts.work_dir', storage_path('app/dompdf')), '/');
        foreach ([$dir, $dir.'/tmp', $dir.'/fonts'] as $path) {
            if (! is_dir($path) && ! @mkdir($path, 0775, true) && ! is_dir($path)) {
                throw new \RuntimeException("Cannot create PDF working folder {$path}. Make storage/ writable.");
            }
        }

        return $dir;
    }

    /** The company logo as a data URI, or null. Local files and our own public URLs are read from disk. */
    private function logoDataUri(): ?string
    {
        $logo = trim((string) Settings::get('company.logo_url', ''));
        if ($logo === '') {
            return null;
        }
        try {
            if (str_starts_with($logo, 'data:image/')) {
                return preg_match('#^data:image/(png|jpe?g|gif|svg\+xml|webp);base64,#', $logo) ? $logo : null;
            }
            $bytes = null;
            $path = parse_url($logo, PHP_URL_PATH) ?: '';
            $host = parse_url($logo, PHP_URL_HOST);
            $local = ! $host || $host === parse_url((string) config('app.url'), PHP_URL_HOST) || $host === request()->getHost();
            if ($local && $path !== '') {
                $file = realpath(public_path(ltrim(rawurldecode($path), '/')));
                $public = realpath(public_path());
                if ($file && $public && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file) && filesize($file) <= self::MAX_LOGO_BYTES) {
                    $bytes = file_get_contents($file);
                }
            }
            if ($bytes === null && preg_match('#^https?://#i', $logo)) {
                $response = Http::timeout(5)->withoutRedirecting()->get($logo);
                if ($response->successful() && strlen($response->body()) <= self::MAX_LOGO_BYTES) {
                    $bytes = $response->body();
                }
            }
            if ($bytes === null || $bytes === '') {
                return null;
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
            if ($mime === 'text/plain' || $mime === 'text/xml' || $mime === 'application/xml') {
                $mime = str_contains($bytes, '<svg') ? 'image/svg+xml' : '';
            }
            if (! in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml', 'image/webp'], true)) {
                return null;
            }

            return 'data:'.$mime.';base64,'.base64_encode($bytes);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
