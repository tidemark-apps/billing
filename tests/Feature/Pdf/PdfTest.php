<?php

namespace Tests\Feature\Pdf;

use App\Mail\BillingMail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Plugins\Hooks;
use App\Services\InvoiceService;
use App\Services\Notifier;
use App\Services\PdfRenderer;
use App\Services\QuoteService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PdfTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceFor(Client $client, bool $issue = true): Invoice
    {
        return app(InvoiceService::class)->create($client, [
            ['description' => "Hébergement café – 東京サーバー €\nLine two of the description", 'unit_price' => 12345, 'taxable' => false],
        ], ['issue' => $issue, 'send' => false, 'notes' => 'Danke schön']);
    }

    private function quoteFor(Client $client, bool $send = true): Quote
    {
        $q = app(QuoteService::class)->create($client, [['description' => 'Консультация / consulting', 'unit_price' => 5000]], ['title' => 'Проект Ünïcode']);

        return $send ? app(QuoteService::class)->send($q) : $q;
    }

    public function test_renders_invoice_and_quote_pdfs_with_unicode(): void
    {
        $client = $this->customer(['name' => 'Zoë Ångström', 'company_name' => 'Müller & Söhne 株式会社', 'currency' => 'EUR', 'country' => 'DE'])->client;
        $renderer = app(PdfRenderer::class);

        $invoice = $this->invoiceFor($client);
        $pdf = $renderer->invoice($invoice);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1500, strlen($pdf));

        $quote = $this->quoteFor($client);
        $this->assertStringStartsWith('%PDF', $renderer->quote($quote));
        $this->assertStringStartsWith('%PDF', $renderer->invoice($this->invoiceFor($client, false)), 'drafts render too');
    }

    public function test_paper_setting_and_country_default_and_cache_dir(): void
    {
        $r = app(PdfRenderer::class);
        $this->assertSame('letter', $r->paper('US'));
        $this->assertSame('a4', $r->paper('DE'));
        Settings::set('billing.paper', 'letter');
        $this->assertSame('letter', $r->paper('DE'));
        $r->invoice($this->invoiceFor($this->customer()->client));
        $this->assertDirectoryExists(config('pdf-fonts.work_dir').'/tmp');
        $this->assertDirectoryExists(config('pdf-fonts.work_dir').'/fonts');
        $this->assertSame('INV-00001.pdf', $r->filename(Invoice::first()));
    }

    public function test_plugin_filter_can_change_view_data(): void
    {
        Hooks::flush();
        Settings::set('company.name', 'Acme Fnord');
        $seen = [];
        Hooks::filter('pdf.data', function (array $data, string $type, $doc) use (&$seen) {
            $seen = [$type, $doc::class];
            $data['paymentInstructions'] = 'Wire to IBAN 123';

            return $data;
        });
        $client = $this->customer()->client;
        $this->assertStringStartsWith('%PDF', app(PdfRenderer::class)->invoice($this->invoiceFor($client)));
        $this->assertSame(['invoice', Invoice::class], $seen);
        Hooks::flush();
    }

    public function test_logo_is_embedded_as_data_uri_from_public_file(): void
    {
        Settings::set('company.logo_url', url('assets/favicon.svg'));
        $captured = null;
        Hooks::filter('pdf.data', function (array $d) use (&$captured) {
            $captured = $d['company']['logo'];

            return $d;
        });
        app(PdfRenderer::class)->invoice($this->invoiceFor($this->customer()->client));
        Hooks::flush();
        $this->assertStringStartsWith('data:image/', (string) $captured);
    }

    public function test_admin_download(): void
    {
        $invoice = $this->invoiceFor($this->customer()->client);
        $quote = $this->quoteFor($invoice->client);

        $res = $this->actingAs($this->admin())->get(route('admin.invoices.pdf', $invoice))->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="INV-00001.pdf"', $res->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->get(route('admin.invoices.pdf', [$invoice, 'inline' => 1]))->assertOk()->assertHeader('Content-Disposition', 'inline; filename="INV-00001.pdf"');
        $this->get(route('admin.quotes.pdf', $quote))->assertOk();
        $this->get(route('admin.invoices.show', $invoice))->assertSee(route('admin.invoices.pdf', $invoice), false);
    }

    public function test_customer_cannot_download_other_clients_pdf_or_use_admin_route(): void
    {
        $mine = $this->customer();
        $other = $this->customer();
        $theirInvoice = $this->invoiceFor($other->client);
        $myInvoice = $this->invoiceFor($mine->client);
        $theirQuote = $this->quoteFor($other->client);
        $myQuote = $this->quoteFor($mine->client);

        $this->actingAs($mine);
        $this->get(route('portal.invoices.pdf', $myInvoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('portal.quotes.pdf', $myQuote))->assertOk();
        $this->get(route('portal.invoices.pdf', $theirInvoice))->assertNotFound();
        $this->get(route('portal.quotes.pdf', $theirQuote))->assertNotFound();
        $this->get(route('admin.invoices.pdf', $myInvoice))->assertStatus(403)->assertDontSee('%PDF');
        $this->get(route('portal.invoices.show', $myInvoice))->assertSee(route('portal.invoices.pdf', $myInvoice), false);
    }

    public function test_guests_are_redirected_from_private_routes(): void
    {
        $invoice = $this->invoiceFor($this->customer()->client);
        $this->get(route('portal.invoices.pdf', $invoice))->assertRedirect(route('login'));
        $this->get(route('admin.invoices.pdf', $invoice))->assertRedirect(route('login'));
    }

    public function test_public_uuid_download_and_drafts_hidden(): void
    {
        $client = $this->customer()->client;
        $invoice = $this->invoiceFor($client);
        $quote = $this->quoteFor($client);
        $draft = $this->invoiceFor($client, false);
        $draftQuote = $this->quoteFor($client, false);

        $this->get(route('invoice.public.pdf', $invoice->uuid))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('quote.public.pdf', $quote->uuid))->assertOk();
        $this->get(route('invoice.public', $invoice->uuid))->assertSee(route('invoice.public.pdf', $invoice->uuid), false);
        $this->get(route('invoice.public.pdf', $draft->uuid))->assertNotFound();
        $this->get(route('quote.public.pdf', $draftQuote->uuid))->assertNotFound();
        $this->get('/invoice/not-a-real-uuid/pdf')->assertNotFound();
    }

    public function test_emails_attach_pdf_when_enabled(): void
    {
        Mail::fake();
        $client = $this->customer()->client;
        $invoice = app(InvoiceService::class)->create($client, [['description' => 'Hosting', 'unit_price' => 1000]], ['issue' => true]);

        Mail::assertSent(BillingMail::class, function ($mail) use ($invoice) {
            $name = $invoice->number.'.pdf';

            return isset($mail->pdfs[$name]) && str_starts_with($mail->pdfs[$name], '%PDF') && count($mail->attachments()) === 1;
        });
    }

    public function test_attachment_can_be_disabled_and_render_failure_still_sends(): void
    {
        $client = $this->customer()->client;
        Settings::set('billing.attach_pdf', false);
        Mail::fake();
        app(InvoiceService::class)->create($client, [['description' => 'A', 'unit_price' => 1000]], ['issue' => true]);
        Mail::assertSent(BillingMail::class, fn ($m) => $m->pdfs === []);

        Settings::set('billing.attach_pdf', true);
        $this->app->bind(PdfRenderer::class, fn () => new class extends PdfRenderer
        {
            public function invoice(Invoice $invoice): string
            {
                throw new \RuntimeException('boom');
            }
        });
        Mail::fake();
        app(InvoiceService::class)->create($client, [['description' => 'B', 'unit_price' => 1000]], ['issue' => true]);
        Mail::assertSent(BillingMail::class, fn ($m) => $m->pdfs === []);
    }

    public function test_reminder_and_receipt_attach_pdf(): void
    {
        Mail::fake();
        $client = $this->customer()->client;
        $invoice = $this->invoiceFor($client);
        app(Notifier::class)->invoiceReminder($invoice);
        Mail::assertSent(BillingMail::class, fn ($m) => count($m->pdfs) === 1);
    }
}
