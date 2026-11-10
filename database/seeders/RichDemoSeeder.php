<?php

namespace Database\Seeders;

use App\Enums\BillingCycle;
use App\Enums\Role;
use App\Enums\ServiceStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\InternalAccounts;
use App\Services\InvoiceService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\QuoteService;
use App\Services\Registration;
use App\Services\TicketService;
use App\Support\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A busy demo install for design reviews:  php artisan db:seed --class=RichDemoSeeder
 * Runs DemoSeeder first, then adds ~60 customers with services, invoices (paid, overdue, draft, refunded),
 * quotes, tickets across departments, staff, and an internal account. Use a throwaway database.
 */
class RichDemoSeeder extends Seeder
{
    public function run(): void
    {
        mt_srand(42);
        fake()->seed(42);
        $this->call(DemoSeeder::class);
        Settings::set('support.enabled', true);

        $staff = $this->staff();
        $departments = $this->departments($staff);
        $products = Product::where('is_active', true)->get();
        $registration = app(Registration::class);
        $orders = app(OrderService::class);
        $payments = app(PaymentService::class);
        $invoices = app(InvoiceService::class);
        $countries = [['US', 'OR', 'Portland'], ['US', 'TX', 'Austin'], ['US', 'NY', 'Brooklyn'], ['GB', null, 'Leeds'], ['DE', null, 'Hamburg'], ['CA', 'ON', 'Toronto'], ['AU', 'NSW', 'Sydney']];

        $clients = [];
        for ($i = 0; $i < 60; $i++) {
            [$country, $state, $city] = $countries[$i % count($countries)];
            $name = fake()->name();
            $company = $i % 3 === 0 ? null : fake()->company();
            $result = $registration->createClient([
                'name' => $name, 'email' => 'demo'.$i.'@'.fake()->unique()->domainName(), 'company_name' => $company,
                'country' => $country, 'state' => $state, 'city' => $city, 'address_1' => fake()->streetAddress(), 'postcode' => fake()->postcode(),
            ], 'password', welcome: false);
            $client = $result['client'];
            $client->forceFill(['created_at' => now()->subDays(mt_rand(5, 330))])->save();
            if ($i % 17 === 5) {
                $client->update(['status' => 'inactive']);
            }
            $clients[] = $client;

            // 1–3 services started over the last year, most paid
            foreach (range(1, mt_rand(1, 3)) as $n) {
                $product = $products->random();
                $cycle = mt_rand(0, 2) === 0 ? BillingCycle::Annually : BillingCycle::Monthly;
                if (! $product->prices()->where('cycle', $cycle->value)->exists()) {
                    $cycle = BillingCycle::Monthly;
                }
                Carbon::setTestNow(now()->subDays(mt_rand(3, 300)));
                try {
                    ['invoice' => $invoice, 'service' => $service] = $orders->place($client, $product, $cycle, ['label' => fake()->domainName(), 'send' => false]);
                    $roll = mt_rand(1, 10);
                    if ($invoice && $invoice->total > 0 && $roll <= 7) {
                        $payments->record($invoice, ['stripe', 'paypal', 'bank'][mt_rand(0, 2)], $invoice->total, 'demo_'.$invoice->id, ['paid_at' => now()]);
                    } elseif ($roll === 8 && $invoice) {
                        $payments->record($invoice, 'stripe', $invoice->total, 'demo_'.$invoice->id, ['paid_at' => now()]);
                        $payments->recordRefund($invoice->payments()->first(), (int) floor($invoice->total / 2), 'demo_refund_'.$invoice->id, 'Partial refund, service credit');
                    }
                    if (mt_rand(1, 12) === 1) {
                        $service->refresh()->update(['status' => ServiceStatus::Suspended]);
                    }
                } finally {
                    Carbon::setTestNow();
                }
            }

            // one-off invoices in assorted states
            if ($i % 4 === 0) {
                $inv = $invoices->create($client, [['description' => fake()->randomElement(['Consulting, 2 hours', 'Site migration', 'Emergency support', 'Domain transfer']), 'quantity' => mt_rand(1, 4), 'unit_price' => mt_rand(40, 150) * 100]], ['issue' => true, 'send' => false, 'due_date' => now()->subDays(mt_rand(1, 40))->toDateString()]);
                if ($i % 8 === 0) {
                    $payments->record($inv, 'bank', $inv->total, 'demo_one_'.$inv->id, ['paid_at' => now()->subDays(mt_rand(0, 10))]);
                }
            }
            if ($i % 9 === 0) {
                $invoices->create($client, [['description' => 'Draft: annual maintenance retainer', 'unit_price' => 120000]], ['issue' => false]);
            }
        }

        // quotes in assorted states
        $quotes = app(QuoteService::class);
        foreach (array_slice($clients, 0, 10) as $n => $client) {
            $quote = $quotes->create($client, [['description' => 'Managed hosting migration', 'unit_price' => mt_rand(20, 90) * 1000], ['description' => 'Training session', 'unit_price' => 15000]], ['title' => fake()->catchPhrase(), 'send' => $n > 1]);
            if ($n % 5 === 3) {
                $quotes->accept($quote->refresh(), $client->name);
            } elseif ($n % 5 === 4) {
                $quotes->decline($quote->refresh());
            }
        }

        // an internal account
        $internal = $registration->createClient(['name' => 'Northwind Ops', 'email' => 'ops@northwind.test', 'company_name' => 'Northwind (internal)', 'country' => 'US'], 'password', welcome: false)['client'];
        app(InternalAccounts::class)->set($internal, true, $staff[0]);
        $orders->place($internal, $products->first(), BillingCycle::Monthly, ['label' => 'status.northwind.test', 'send' => false]);

        $this->tickets($departments, $staff, $clients);
    }

    /** @return array<int, User> */
    private function staff(): array
    {
        $admin = User::where('email', 'admin@example.com')->first();
        $people = [$admin];
        foreach ([['Sam Rivera', 'sam@example.com'], ['Jo Park', 'jo@example.com'], ['Dana Okafor', 'dana@example.com']] as [$name, $email]) {
            $people[] = User::updateOrCreate(['email' => $email], ['name' => $name, 'password' => 'password', 'role' => Role::Staff, 'is_active' => true]);
        }

        return $people;
    }

    /** @return array<string, Department> */
    private function departments(array $staff): array
    {
        $defs = [
            'support' => ['Technical support', 'Something broken? We will help.', true, 'support@northwind.test', [1, 2]],
            'billing' => ['Billing', 'Invoices, payments, refunds.', true, 'billing@northwind.test', [2, 3]],
            'sales' => ['Sales', 'Pre-sales questions and quotes.', true, 'sales@northwind.test', [3]],
            'abuse' => ['Abuse reports', null, false, 'abuse@northwind.test', [1]],
        ];
        $out = [];
        foreach ($defs as $slug => [$name, $desc, $public, $email, $members]) {
            $d = Department::create(['name' => $name, 'slug' => $slug, 'description' => $desc, 'is_public' => $public, 'is_active' => true, 'email' => $email, 'auto_reply' => true]);
            $d->staff()->sync(array_map(fn ($k) => $staff[$k]->id, $members));
            $out[$slug] = $d;
        }

        return $out;
    }

    private function tickets(array $departments, array $staff, array $clients): void
    {
        $svc = app(TicketService::class);
        $subjects = [
            'support' => ['Site is returning 502 errors', 'Cannot log in to control panel', 'SSL certificate not renewing', 'Email bouncing since yesterday', 'How do I restore a backup?', 'Slow page loads after migration'],
            'billing' => ['Charged twice this month', 'Need a VAT invoice', 'Update card on file', 'Refund request for unused months', 'Why was my service suspended?'],
            'sales' => ['Pricing for 20 sites', 'Do you offer reseller plans?', 'Quote for migration of 5 domains'],
            'abuse' => ['Phishing page on a customer domain'],
        ];
        $statuses = [TicketStatus::Open, TicketStatus::Answered, TicketStatus::CustomerReply, TicketStatus::OnHold, TicketStatus::Closed, TicketStatus::Closed];
        $n = 0;
        foreach ($subjects as $slug => $list) {
            foreach ($list as $subject) {
                $client = $clients[($n * 3) % count($clients)];
                $user = User::where('client_id', $client->id)->first();
                Carbon::setTestNow(now()->subDays(mt_rand(0, 25))->subHours(mt_rand(0, 20)));
                try {
                    $ticket = $svc->open([
                        'department' => $departments[$slug], 'subject' => $subject, 'client' => $client, 'user' => $user,
                        'body' => "Hi,\n\n".$subject.". It started a couple of days ago and I have not changed anything on my side. Could you take a look?\n\nThanks!",
                        'priority' => [TicketPriority::Low, TicketPriority::Normal, TicketPriority::Normal, TicketPriority::High, TicketPriority::Urgent][mt_rand(0, 4)]->value,
                        'notify' => false, 'service' => Service::where('client_id', $client->id)->first(),
                    ]);
                    $status = $statuses[$n % count($statuses)];
                    if ($status !== TicketStatus::Open) {
                        $members = $departments[$slug]->staff()->get();
                        $agent = $members[$n % $members->count()];
                        $svc->assign($ticket, $agent, $agent);
                        $svc->note($ticket, $agent, 'Checked the logs: looks like a config issue on the customer side. Replying with steps.');
                        $svc->reply($ticket, "Thanks for reaching out. Could you try clearing the cache and let us know if it persists?\n\n— ".$agent->name, $agent, true, [], ['status' => TicketStatus::Answered]);
                    }
                    if (in_array($status, [TicketStatus::CustomerReply, TicketStatus::Closed], true)) {
                        $svc->reply($ticket->refresh(), 'That fixed it, thank you!', $user, false);
                    }
                    if ($status === TicketStatus::Closed) {
                        $svc->close($ticket->refresh(), $agent);
                    } elseif ($status === TicketStatus::OnHold) {
                        $svc->setStatus($ticket->refresh(), $status, $agent);
                    }
                } finally {
                    Carbon::setTestNow();
                }
                $n++;
            }
        }
    }
}
