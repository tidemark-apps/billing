<?php

namespace Database\Seeders;

use App\Enums\BillingCycle;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\QuoteService;
use App\Services\Registration;
use App\Services\UsageService;
use App\Support\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Sample data for development and screenshots:  php artisan db:seed --class=DemoSeeder
 * Admin: admin@example.com / password    Customer: customer@example.com / password
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        Settings::setMany(['company.name' => 'Northwind Hosting', 'company.email' => 'billing@northwind.test', 'company.address' => "12 Harbour Street\nPortland, OR 97201", 'billing.currency' => 'USD']);
        Settings::set('general.registration', true);
        User::updateOrCreate(['email' => 'admin@example.com'], ['name' => 'Alex Admin', 'password' => 'password', 'role' => Role::Admin]);
        TaxRate::firstOrCreate(['name' => 'Sales tax', 'country' => 'US'], ['rate' => 7.5]);
        TaxRate::firstOrCreate(['name' => 'VAT', 'country' => 'GB'], ['rate' => 20]);

        $hosting = ProductGroup::create(['name' => 'Web hosting', 'slug' => 'web-hosting', 'description' => 'Fast, reliable hosting for sites of every size.']);
        $extras = ProductGroup::create(['name' => 'Add-ons', 'slug' => 'add-ons', 'sort' => 2]);
        $products = [
            ['Starter hosting', 'starter-hosting', $hosting, "10 GB SSD storage\n1 website\nFree SSL certificate\nDaily backups\nEmail support", 500, 0],
            ['Business hosting', 'business-hosting', $hosting, "50 GB SSD storage\nUp to 5 websites\nStaging environment\nFree SSL and daily backups\nPriority support", 1200, 0],
            ['Agency hosting', 'agency-hosting', $hosting, "200 GB SSD storage\nUnlimited websites\nWhite-label client access\nStaging and daily backups\nPriority support with a 1 hour response", 3500, 1500],
            ['Premium SSL certificate', 'premium-ssl', $extras, "Organisation-validated certificate\nCovers your main domain and www\nWarranty included\nReissue any time", 4900, 0],
        ];
        $made = [];
        foreach ($products as [$name, $slug, $group, $desc, $price, $setup]) {
            $p = Product::create(['name' => $name, 'slug' => $slug, 'product_group_id' => $group->id, 'description' => $desc, 'label_prompt' => 'Domain name', 'is_active' => true, 'is_visible' => true]);
            $p->prices()->create(['cycle' => BillingCycle::Monthly, 'currency' => 'USD', 'amount' => $price, 'setup_fee' => $setup]);
            $p->prices()->create(['cycle' => BillingCycle::Annually, 'currency' => 'USD', 'amount' => $price * 10, 'setup_fee' => $setup]);
            $made[$slug] = $p;
        }

        // A metered example: small base fee, API calls with a free allowance and graduated tiers, bandwidth per GB.
        $cloud = Product::create(['name' => 'Cloud API (metered)', 'slug' => 'cloud-api', 'product_group_id' => $extras->id, 'description' => "10,000 API calls included each month\nGraduated pricing after that\n50 GB bandwidth included, then per GB\nUsage shown live in your client area", 'label_prompt' => 'Project name', 'is_active' => true, 'is_visible' => true]);
        $cloud->prices()->create(['cycle' => BillingCycle::Monthly, 'currency' => 'USD', 'amount' => 500, 'setup_fee' => 0]);
        $calls = $cloud->meters()->create(['key' => 'api_calls', 'name' => 'API calls', 'unit' => 'calls', 'aggregation' => 'sum', 'included_quantity' => 10000, 'pricing_model' => 'tiered', 'sort' => 0]);
        // prices are micro-minor units (1/1,000,000 of a cent): 200,000 = 0.2 cent = $0.002 per call
        $calls->prices()->create(['currency' => 'USD', 'unit_price' => 0, 'tiers' => [['up_to' => '90000', 'unit_price' => 200000, 'flat_fee' => 0], ['up_to' => null, 'unit_price' => 100000, 'flat_fee' => 0]]]);
        $bandwidth = $cloud->meters()->create(['key' => 'bandwidth_gb', 'name' => 'Bandwidth', 'unit' => 'GB', 'aggregation' => 'sum', 'included_quantity' => 50, 'pricing_model' => 'per_unit', 'sort' => 1]);
        $bandwidth->prices()->create(['currency' => 'USD', 'unit_price' => 8000000]); // 8 cents per GB

        $registration = app(Registration::class);
        $orders = app(OrderService::class);
        $payments = app(PaymentService::class);
        $names = [['Customer Demo', 'customer@example.com', 'Demo Studio LLC'], ['Priya Raman', 'priya@raman.test', 'Raman & Co'], ['Tom Becker', 'tom@becker.test', null], ['Lena Fischer', 'lena@fischer.test', 'Fischer Design'], ['Marcus Hill', 'marcus@hill.test', null]];

        foreach ($names as $i => [$name, $email, $company]) {
            $result = $registration->createClient(['name' => $name, 'email' => $email, 'company_name' => $company, 'country' => 'US', 'address_1' => (100 + $i * 7).' Market Street', 'city' => 'Portland', 'state' => 'OR', 'postcode' => '97201'], 'password', welcome: false);
            $client = $result['client'];
            foreach (array_slice(array_values($made), $i % 3, 2) as $n => $product) {
                $months = 11 - $i * 2 - $n;
                $this->travelBack($months);
                ['invoice' => $invoice] = $orders->place($client, $product, $n === 0 ? BillingCycle::Monthly : BillingCycle::Annually, ['label' => str($name)->slug().($n ? '.net' : '.com'), 'send' => false]);
                $payments->record($invoice, $n ? 'stripe' : 'paypal', $invoice->total, 'seed_'.$invoice->id, ['paid_at' => now()]);
                $this->travelBack(0);
            }
        }

        $client = Client::where('email', 'customer@example.com')->first();
        ['service' => $cloudService, 'invoice' => $cloudInvoice] = $orders->place($client, $cloud, BillingCycle::Monthly, ['label' => 'demo-project', 'send' => false]);
        $payments->record($cloudInvoice, 'stripe', $cloudInvoice->total, 'seed_cloud_'.$cloudInvoice->id, ['paid_at' => now()]);
        $usage = app(UsageService::class);
        foreach ([[3, 6200, 11], [2, 7400, 14], [1, 5100, 9]] as $n => [$daysAgo, $callCount, $gb]) {
            $usage->record($cloudService->refresh(), 'api_calls', $callCount, now()->subDays($daysAgo), 'seed-calls-'.$n, 'seed');
            $usage->record($cloudService, 'bandwidth_gb', $gb, now()->subDays($daysAgo), 'seed-gb-'.$n, 'seed');
        }
        app(InvoiceService::class)->create($client, [['description' => 'Migration assistance (3 hours)', 'quantity' => 3, 'unit_price' => 9000]], ['issue' => true, 'send' => false, 'due_date' => now()->subDays(4)->toDateString()]);
        app(InvoiceService::class)->create($client, [['description' => 'Priority support, one month', 'unit_price' => 2500]], ['issue' => true, 'send' => false]);
        app(QuoteService::class)->create($client, [
            ['description' => 'Agency hosting (annual)', 'unit_price' => 35000, 'product_id' => $made['agency-hosting']->id, 'billing_cycle' => BillingCycle::Annually, 'label' => 'demo-studio.com'],
            ['description' => 'Site migration', 'unit_price' => 20000],
        ], ['title' => 'Move Demo Studio to Northwind', 'send' => true]);
    }

    private function travelBack(int $months): void
    {
        Carbon::setTestNow($months ? now()->startOfMonth()->subMonths($months)->addDays(9) : null);
    }
}
