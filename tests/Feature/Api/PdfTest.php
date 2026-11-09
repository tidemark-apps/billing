<?php

namespace Tests\Feature\Api;

use App\Services\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Invoice and quote PDFs over the API: customers get their own, staff get everything, and drafts stay hidden from customers. */
class PdfTest extends TestCase
{
    use ApiTestHelpers, RefreshDatabase;

    private function quote($client, bool $send = true)
    {
        $quote = app(QuoteService::class)->create($client, [['description' => 'Consulting', 'unit_price' => 5000]]);

        return $send ? app(QuoteService::class)->send($quote) : $quote;
    }

    public function test_a_customer_downloads_their_own_invoice_pdf(): void
    {
        $user = $this->customer();
        $invoice = $this->makeInvoice($user->client);

        $res = $this->as($user)->get("/api/v1/invoices/{$invoice->uuid}/pdf")->assertOk();
        $res->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment; filename="'.$invoice->number.'.pdf"', $res->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $res->getContent());

        $this->as($user)->get("/api/v1/invoices/{$invoice->uuid}/pdf?inline=1")->assertOk()->assertHeader('Content-Disposition', 'inline; filename="'.$invoice->number.'.pdf"');
    }

    public function test_a_customer_downloads_their_own_quote_pdf(): void
    {
        $user = $this->customer();
        $quote = $this->quote($user->client);

        $res = $this->as($user)->get("/api/v1/quotes/{$quote->uuid}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
    }

    public function test_customers_cannot_download_other_clients_or_draft_documents(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        $invoiceB = $this->makeInvoice($b->client);
        $quoteB = $this->quote($b->client);
        $draft = $this->makeInvoice($a->client, false);
        $draftQuote = $this->quote($a->client, false);

        $this->as($a)->get("/api/v1/invoices/{$invoiceB->uuid}/pdf")->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->as($a)->get("/api/v1/quotes/{$quoteB->uuid}/pdf")->assertNotFound();
        $this->as($a)->get("/api/v1/invoices/{$draft->uuid}/pdf")->assertNotFound();
        $this->as($a)->get("/api/v1/quotes/{$draftQuote->uuid}/pdf")->assertNotFound();
        $this->as($a)->get('/api/v1/invoices/not-a-uuid/pdf')->assertNotFound();
    }

    public function test_pdf_routes_need_a_token_and_the_matching_ability(): void
    {
        $user = $this->customer();
        $invoice = $this->makeInvoice($user->client);
        $quote = $this->quote($user->client);

        $this->flushHeaders()->getJson("/api/v1/invoices/{$invoice->uuid}/pdf")->assertStatus(401);
        $this->flushHeaders()->getJson("/api/v1/quotes/{$quote->uuid}/pdf")->assertStatus(401);
        $this->as($user, ['quotes.read'])->getJson("/api/v1/invoices/{$invoice->uuid}/pdf")->assertStatus(403);
        $this->as($user, ['invoices.read'])->getJson("/api/v1/quotes/{$quote->uuid}/pdf")->assertStatus(403);
        $this->as($user, ['invoices.read'])->get("/api/v1/invoices/{$invoice->uuid}/pdf")->assertOk();
        $this->as($user, ['quotes.read'])->get("/api/v1/quotes/{$quote->uuid}/pdf")->assertOk();
        // a staff token is not a customer token
        $this->as($this->staffUser())->getJson("/api/v1/invoices/{$invoice->uuid}/pdf")->assertStatus(403);
    }

    public function test_staff_download_any_invoice_and_quote_including_drafts(): void
    {
        $client = $this->customer()->client;
        $draft = $this->makeInvoice($client, false);
        $invoice = $this->makeInvoice($client);
        $quote = $this->quote($client, false);
        $staff = $this->staffUser();

        foreach ([$draft, $invoice] as $i) {
            $res = $this->as($staff)->get("/api/v1/admin/invoices/{$i->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $res->getContent());
        }
        $this->as($staff)->get("/api/v1/admin/quotes/{$quote->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->as($staff)->getJson('/api/v1/admin/invoices/999999/pdf')->assertNotFound();
    }

    public function test_staff_pdf_routes_enforce_ability_and_audience(): void
    {
        $client = $this->customer();
        $invoice = $this->makeInvoice($client->client);
        $quote = $this->quote($client->client);
        $staff = $this->staffUser();

        $this->flushHeaders()->getJson("/api/v1/admin/invoices/{$invoice->id}/pdf")->assertStatus(401);
        $this->as($client)->getJson("/api/v1/admin/invoices/{$invoice->id}/pdf")->assertStatus(403);
        $this->as($staff, ['quotes.read'])->getJson("/api/v1/admin/invoices/{$invoice->id}/pdf")->assertStatus(403);
        $this->as($staff, ['invoices.read'])->getJson("/api/v1/admin/quotes/{$quote->id}/pdf")->assertStatus(403);
        $this->as($staff, ['quotes.read'])->get("/api/v1/admin/quotes/{$quote->id}/pdf")->assertOk();
    }
}
