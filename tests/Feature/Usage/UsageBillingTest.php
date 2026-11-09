<?php

namespace Tests\Feature\Usage;

use App\Contracts\ReportsUsage;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\TaxRate;
use App\Models\UsageRecord;
use App\Plugins\ModuleRegistry;
use App\Services\BillingRunner;
use App\Services\PaymentService;
use App\Services\ProvisioningService;
use App\Services\UsageService;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeModule;

class UsageBillingTest extends UsageTestCase
{
    private function runner(): BillingRunner
    {
        return app(BillingRunner::class);
    }

    private function dueDate(Service $service): Carbon
    {
        return $service->next_due_date->copy()->startOfDay();
    }

    private function latestInvoice(Service $service): Invoice
    {
        return Invoice::where('client_id', $service->client_id)->latest('id')->firstOrFail();
    }

    public function test_renewal_invoice_gets_usage_lines_and_second_run_does_not_double_bill(): void
    {
        [$service] = $this->meteredService(['included_quantity' => 500]);
        $due = $this->dueDate($service);
        app(UsageService::class)->record($service, 'api_calls', 1000, $due->copy()->subDays(10), 'a');
        app(UsageService::class)->record($service, 'api_calls', 500, $due->copy()->subDays(2), 'b');

        $this->travelTo($due->copy()->addHours(3));
        $this->assertSame(1, $this->runner()->generateRenewalInvoices());
        $this->assertSame(0, $this->runner()->generateRenewalInvoices());
        $this->runner()->run();
        $this->assertSame(0, $this->runner()->generateRenewalInvoices());

        $invoice = $this->latestInvoice($service);
        $items = $invoice->items()->orderBy('sort')->get();
        $this->assertSame(['renewal', 'usage'], $items->pluck('kind')->all());
        $usage = $items[1];
        $this->assertSame(200, $usage->unit_price);               // (1500 - 500) x 0.2 cent
        $this->assertSame(1200, $invoice->subtotal);
        $this->assertStringContainsString('API calls', $usage->description);
        $this->assertStringContainsString('1,500 calls', $usage->description);
        $this->assertStringContainsString('500 included', $usage->description);
        $this->assertSame(1, Invoice::where('client_id', $service->client_id)->where('id', '>', 0)->where('status', InvoiceStatus::Open->value)->count());
        $this->assertSame(2, UsageRecord::whereNotNull('invoiced_at')->where('invoice_item_id', $usage->id)->count());
    }

    public function test_usage_after_cutoff_lands_on_the_next_invoice(): void
    {
        [$service] = $this->meteredService();
        $due = $this->dueDate($service);
        $usage = app(UsageService::class);
        $usage->record($service, 'api_calls', 100, $due->copy()->subDay(), 'in-period');
        $usage->record($service, 'api_calls', 300, $due->copy()->addHour(), 'after-cutoff');

        $this->travelTo($due->copy()->addHours(3));
        $this->runner()->generateRenewalInvoices();
        $first = $this->latestInvoice($service);
        $this->assertSame(20, $first->items()->where('kind', 'usage')->value('unit_price')); // only the 100 calls
        app(PaymentService::class)->record($first, 'test', $first->total, 'pay-1');

        $next = $service->refresh()->next_due_date->copy()->startOfDay();
        $this->travelTo($next->copy()->addHours(3));
        $this->runner()->generateRenewalInvoices();
        $second = $this->latestInvoice($service);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(60, $second->items()->where('kind', 'usage')->value('unit_price')); // the 300 calls
    }

    public function test_metered_services_are_invoiced_at_period_end_not_early(): void
    {
        [$service] = $this->meteredService();
        $this->travelTo($this->dueDate($service)->subDays(5));
        $this->assertSame(0, $this->runner()->generateRenewalInvoices());

        Settings::set('billing.usage_invoice_days_before', 7);
        $this->assertSame(1, $this->runner()->generateRenewalInvoices());
    }

    public function test_zero_base_price_product_still_invoices_usage(): void
    {
        [$service] = $this->meteredService(base: 0);
        $this->assertSame(ServiceStatus::Active, $service->status);
        $due = $this->dueDate($service);
        app(UsageService::class)->record($service, 'api_calls', 5000, $due->copy()->subDays(3));

        $this->travelTo($due->copy()->addHours(3));
        $this->runner()->generateRenewalInvoices();

        $invoice = $this->latestInvoice($service);
        $this->assertSame(1000, $invoice->total);
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertSame(1, $invoice->items()->where('kind', 'usage')->count());
    }

    public function test_tax_applies_to_usage_lines_unless_product_is_exempt(): void
    {
        TaxRate::create(['name' => 'VAT', 'rate' => 10, 'country' => 'US']);
        [$service] = $this->meteredService();
        $due = $this->dueDate($service);
        app(UsageService::class)->record($service, 'api_calls', 5000, $due->copy()->subDays(3));

        $this->travelTo($due->copy()->addHours(3));
        $this->runner()->generateRenewalInvoices();

        $invoice = $this->latestInvoice($service);
        $this->assertSame(2000, $invoice->subtotal);     // 1000 base + 1000 usage
        $this->assertSame(200, $invoice->tax_total);
        $this->assertTrue((bool) $invoice->items()->where('kind', 'usage')->value('taxable'));

        // a tax-exempt product leaves its usage untaxed too
        $this->travelBack();
        [$exempt] = $this->meteredService();
        $exempt->product->update(['tax_exempt' => true]);
        $due = $this->dueDate($exempt);
        app(UsageService::class)->record($exempt, 'api_calls', 5000, $due->copy()->subDays(3));
        $this->travelTo($due->copy()->addHours(3));
        $this->runner()->generateRenewalInvoices();
        $this->assertSame(0, $this->latestInvoice($exempt)->tax_total);
    }

    public function test_zero_cost_lines_are_skipped_unless_configured(): void
    {
        [$service] = $this->meteredService(['included_quantity' => 10000]);
        $due = $this->dueDate($service);
        app(UsageService::class)->record($service, 'api_calls', 50, $due->copy()->subDays(3));

        $this->travelTo($due->copy()->addHours(3));
        $this->runner()->generateRenewalInvoices();
        $invoice = $this->latestInvoice($service);
        $this->assertSame(0, $invoice->items()->where('kind', 'usage')->count());
        $this->assertNotNull(UsageRecord::first()->invoiced_at, 'zero-cost usage is still settled');
        $this->assertNull(UsageRecord::first()->invoice_item_id);

        app(PaymentService::class)->record($invoice, 'test', $invoice->total, 'p');
        Settings::set('billing.usage_zero_lines', true);
        $next = $service->refresh()->next_due_date->copy()->startOfDay();
        app(UsageService::class)->record($service, 'api_calls', 50, $next->copy()->subDays(3));
        $this->travelTo($next->copy()->addHours(3));
        $this->runner()->generateRenewalInvoices();
        $line = $this->latestInvoice($service)->items()->where('kind', 'usage')->first();
        $this->assertNotNull($line);
        $this->assertSame(0, $line->unit_price);
    }

    public function test_termination_bills_remaining_usage_once(): void
    {
        app(ModuleRegistry::class)->register(new FakeModule);
        [$service] = $this->meteredService();
        app(UsageService::class)->record($service, 'api_calls', 2500, now()->subHour(), 'x');
        $before = Invoice::count();

        app(ProvisioningService::class)->terminate($service->refresh());

        $this->assertSame($before + 1, Invoice::count());
        $invoice = $this->latestInvoice($service);
        $this->assertSame(500, $invoice->total);
        $this->assertSame(['usage'], $invoice->items->pluck('kind')->all());
        $this->assertSame(InvoiceStatus::Open, $invoice->status);

        // cancelling after termination, or calling again, never bills the same usage twice
        $this->assertNull($this->runner()->billFinalUsage($service->refresh()));
        app(ProvisioningService::class)->cancel($service->refresh(), immediately: true);
        $this->assertSame($before + 1, Invoice::count());
    }

    public function test_cancelling_at_period_end_bills_final_usage(): void
    {
        [$service] = $this->meteredService();
        app(UsageService::class)->record($service, 'api_calls', 1000, now()->subHour(), 'x');
        app(ProvisioningService::class)->cancel($service->refresh(), immediately: true, reason: 'bye');

        $this->assertSame(ServiceStatus::Cancelled, $service->refresh()->status);
        $this->assertSame(200, $this->latestInvoice($service)->total);
    }

    public function test_terminating_without_usage_creates_no_invoice(): void
    {
        [$service] = $this->meteredService();
        $before = Invoice::count();
        app(ProvisioningService::class)->terminate($service->refresh());
        $this->assertSame($before, Invoice::count());
    }

    public function test_pull_contract_records_reported_usage_once_per_window(): void
    {
        $module = new class extends FakeModule implements ReportsUsage
        {
            public int $calls = 0;

            public function key(): string
            {
                return 'puller';
            }

            public function pullUsage(Service $service, CarbonInterface $from, CarbonInterface $to): array
            {
                $this->calls++;

                return ['api_calls' => 120, 'unknown_meter' => 5];
            }
        };
        app(ModuleRegistry::class)->register($module);
        [$service] = $this->meteredService();
        $service->product->update(['module' => 'puller']);
        $this->travelTo(now()->addDays(2));

        $this->assertSame(1, app(UsageService::class)->pullAll());
        $this->assertSame(0, app(UsageService::class)->pullAll(), 'the window is already pulled');
        $this->assertSame(1, $module->calls);
        $record = UsageRecord::firstOrFail();
        $this->assertSame('pull', $record->source);
        $this->assertEquals(120, $record->quantity);
    }
}
