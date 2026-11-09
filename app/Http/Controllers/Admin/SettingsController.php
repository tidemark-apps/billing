<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Plugins\GatewayRegistry;
use App\Services\BillingRunner;
use App\Services\Notifier;
use App\Support\Activity;
use App\Support\Fields;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    private const SECTIONS = ['general' => 'General', 'billing' => 'Billing', 'email' => 'Email', 'payments' => 'Payments', 'security' => 'Security', 'system' => 'System'];

    public function show(Request $request, GatewayRegistry $gateways, ?string $section = 'general')
    {
        $section ??= 'general';
        $this->authorizeAdmin($request);

        return view('admin.settings.index', [
            'section' => $section,
            'sections' => self::SECTIONS,
            'groups' => $this->groups($section),
            'values' => $this->values($section),
            'gateways' => $section === 'payments' ? $gateways->all() : [],
            'gatewayRegistry' => $gateways,
            'system' => $section === 'system' ? $this->systemInfo() : [],
        ]);
    }

    public function update(Request $request, string $section): RedirectResponse
    {
        $this->authorizeAdmin($request);

        if ($section === 'payments') {
            return $this->updateGateways($request);
        }
        $fields = $this->flatFields($section);
        $input = $request->validate(Fields::rules($fields, 's.') + ['s' => ['array']])['s'] ?? [];

        foreach ($fields as $f) {
            $formKey = $f['key'];
            $key = $this->unform($formKey);
            $collected = Fields::collect([$f], $input);
            if (! array_key_exists($formKey, $collected)) {
                continue; // absent, or a password left blank to keep the stored one
            }
            $value = $collected[$formKey];
            if (($f['type'] ?? 'text') === 'checkbox') {
                Settings::set($key, $value ? 'true' : 'false');
            } elseif ($value === null || $value === '') {
                Settings::forget($key);
            } else {
                Settings::set($key, $value, ($f['type'] ?? 'text') === 'password');
            }
        }
        Activity::log('settings.updated', null, "Updated $section settings");

        return redirect()->route('admin.settings.index', $section)->with('status', 'Settings saved.');
    }

    public function testEmail(Request $request, Notifier $notifier): RedirectResponse
    {
        $this->authorizeAdmin($request);
        try {
            Mail::raw('If you can read this, outgoing email from '.config('billing.name').' works.', fn ($m) => $m->to($request->user()->email)->subject('Test email'));

            return back()->with('status', 'Test email sent to '.$request->user()->email.'.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not send: '.$e->getMessage());
        }
    }

    public function regenerateCronToken(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        Settings::set('system.cron_token', Str::random(40), true);

        return back()->with('status', 'New cron URL generated. Update your cron job.');
    }

    public function runBilling(Request $request, BillingRunner $runner): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $result = $runner->run();
        Activity::log('billing.run', null, 'Ran billing tasks manually', $result);

        return back()->with('status', 'Billing tasks ran: '.collect($result)->map(fn ($n, $k) => str_replace('_', ' ', $k).' '.$n)->implode(', ').'.');
    }

    private function updateGateways(Request $request): RedirectResponse
    {
        $registry = app(GatewayRegistry::class);
        $data = $request->input('gateways', []);

        foreach ($registry->all() as $key => $gateway) {
            if (! isset($data[$key])) {
                continue;
            }
            $request->validate(Fields::rules($gateway->fields(), "gateways.$key.") + ["gateways.$key.title" => ['nullable', 'string', 'max:100']]);
            $input = $data[$key];
            Settings::set("gateway.$key.enabled", ! empty($input['enabled']) ? 'true' : 'false');
            ($t = trim((string) ($input['title'] ?? ''))) !== '' ? Settings::set("gateway.$key.title", $t) : Settings::forget("gateway.$key.title");
            $secrets = Fields::secretKeys($gateway->fields());
            foreach (Fields::collect($gateway->fields(), $input) as $field => $value) {
                if ($value === null || $value === '') {
                    Settings::forget("gateway.$key.$field");
                } else {
                    Settings::set("gateway.$key.$field", is_bool($value) ? ($value ? 'true' : 'false') : $value, in_array($field, $secrets, true));
                }
            }
        }
        Activity::log('settings.updated', null, 'Updated payment gateway settings');

        return redirect()->route('admin.settings.index', 'payments')->with('status', 'Payment settings saved.');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only administrators can change settings.');
    }

    /** Dotted setting keys cannot be form field names (PHP rewrites dots), so forms use "__". */
    private function form(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    private function unform(string $key): string
    {
        return str_replace('__', '.', $key);
    }

    /** @return array<int, array<string, mixed>> */
    private function flatFields(string $section): array
    {
        return collect($this->groups($section))->pluck('fields')->flatten(1)->all();
    }

    /** @return array<string, mixed> form-name => current value */
    private function values(string $section): array
    {
        $out = [];
        foreach ($this->flatFields($section) as $f) {
            $out[$f['key']] = Settings::get($this->unform($f['key']));
        }

        return $out;
    }

    /** @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}> */
    private function groups(string $section): array
    {
        $f = fn (string $key, string $label, array $extra = []) => ['key' => $this->form($key), 'label' => $label] + $extra;

        return match ($section) {
            'general' => [
                ['title' => 'Your business', 'description' => 'Shown on invoices, quotes, emails and the client area.', 'fields' => [
                    $f('company.name', 'Business name', ['required' => true, 'default' => config('billing.name')]),
                    $f('company.email', 'Contact email', ['type' => 'email', 'help' => 'Where admin notifications (accepted quotes, provisioning failures) are sent.']),
                    $f('company.address', 'Address', ['type' => 'textarea', 'help' => 'Printed on invoices.']),
                    $f('company.tax_id', 'Tax / VAT number'),
                    $f('company.logo_url', 'Logo URL', ['type' => 'url', 'help' => 'A link to a logo image for the client area and login page. Leave blank to use the Tidemark mark.']),
                ]],
                ['title' => 'Client accounts', 'description' => 'How customers get into the client area.', 'fields' => [
                    $f('general.registration', 'Allow self-registration', ['type' => 'checkbox', 'default' => true, 'help' => 'Visitors can create their own client account. Turn off to create accounts yourself.']),
                ]],
            ],
            'billing' => [
                ['title' => 'Invoicing', 'description' => 'Defaults for new invoices and quotes.', 'fields' => [
                    $f('billing.currency', 'Store currency', ['type' => 'select', 'options' => Money::currencies(), 'default' => config('billing.currency'), 'help' => 'The default for new clients and the fallback for product prices.']),
                    $f('billing.invoice_prefix', 'Invoice number prefix', ['default' => 'INV-']),
                    $f('billing.quote_prefix', 'Quote number prefix', ['default' => 'QUO-']),
                    $f('billing.due_days', 'Payment terms (days)', ['type' => 'number', 'default' => 7]),
                    $f('billing.quote_valid_days', 'Quote validity (days)', ['type' => 'number', 'default' => 30]),
                    $f('billing.quote_auto_invoice', 'Invoice automatically when a quote is accepted', ['type' => 'checkbox', 'default' => true]),
                    $f('billing.attach_pdf', 'Attach the PDF to invoice emails', ['type' => 'checkbox', 'default' => true, 'help' => 'New invoice, reminder and receipt emails include the invoice as a PDF.']),
                    $f('billing.paper', 'PDF paper size', ['type' => 'select', 'options' => ['' => 'Automatic (by client country)', 'a4' => 'A4', 'letter' => 'US Letter']]),
                    $f('billing.payment_instructions', 'Payment instructions', ['type' => 'textarea', 'help' => 'Bank details or other instructions printed on unpaid invoice PDFs.']),
                    $f('billing.terms', 'Default terms', ['type' => 'textarea', 'help' => 'Printed at the foot of new invoices and quotes.']),
                ]],
                ['title' => 'Recurring billing', 'description' => 'Renewal invoices and what happens to unpaid services. Runs daily from the cron job.', 'fields' => [
                    $f('billing.invoice_days_before', 'Create renewal invoices this many days before due', ['type' => 'number', 'default' => 14]),
                    $f('billing.reminder_days', 'Reminder schedule (days from due date)', ['default' => '-3,1,7,14', 'help' => 'Comma-separated. Negative numbers are before the due date.']),
                    $f('billing.suspend_after_days', 'Suspend services this many days overdue', ['type' => 'number', 'default' => 5, 'help' => '0 turns automatic suspension off.']),
                    $f('billing.terminate_after_days', 'Terminate suspended services this many days overdue', ['type' => 'number', 'default' => 30, 'help' => '0 turns automatic termination off.']),
                ]],
                ['title' => 'Automatic payments', 'description' => 'Charging customers\' saved payment methods. Runs hourly from the cron job.', 'fields' => [
                    $f('autopay.enabled', 'Charge saved payment methods automatically', ['type' => 'checkbox', 'default' => true, 'help' => 'Only for customers who turned automatic payment on for a saved method.']),
                    $f('autopay.days_before_due', 'Charge this many days before the due date', ['type' => 'number', 'default' => 0, 'help' => '0 charges on the due date.']),
                    $f('autopay.renewals_at_creation', 'Charge renewal invoices as soon as they are created', ['type' => 'checkbox', 'default' => false, 'help' => 'Instead of waiting for the due date.']),
                    $f('autopay.retry_days', 'Retry failed payments (days after the first attempt)', ['default' => '1,3,5', 'help' => 'Comma-separated. The customer is emailed after each failure.']),
                    $f('autopay.max_attempts', 'Maximum attempts per invoice', ['type' => 'number', 'default' => 4, 'help' => 'The first charge plus retries. After the last failure autopay stops for that invoice and the customer and admin are notified.']),
                ]],
            ],
            'email' => [
                ['title' => 'Outgoing email', 'description' => 'Invoices, receipts and reminders are sent from here.', 'fields' => [
                    $f('mail.enabled', 'Send emails', ['type' => 'checkbox', 'default' => true]),
                    $f('mail.mailer', 'Method', ['type' => 'select', 'options' => ['' => 'Use server default (.env)', 'smtp' => 'SMTP', 'sendmail' => 'Sendmail', 'log' => 'Log only (testing)']]),
                    $f('mail.host', 'SMTP host'),
                    $f('mail.port', 'SMTP port', ['type' => 'number', 'default' => 587]),
                    $f('mail.encryption', 'Encryption', ['type' => 'select', 'options' => ['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)'], 'default' => 'tls']),
                    $f('mail.username', 'SMTP username'),
                    $f('mail.password', 'SMTP password', ['type' => 'password']),
                    $f('mail.from_address', 'From address', ['type' => 'email']),
                    $f('mail.from_name', 'From name'),
                ]],
            ],
            'security' => [
                ['title' => 'Two-factor authentication', 'description' => 'Staff and clients can protect their own logins from their security page. Locked out? Run php artisan billing:reset-2fa you@example.com on the server.', 'fields' => [
                    $f('security.require_staff_2fa', 'Require two-factor authentication for staff', ['type' => 'checkbox', 'default' => false, 'help' => 'Staff and administrators without it are sent to set it up before they can use the admin area.']),
                ]],
            ],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function systemInfo(): array
    {
        $token = (string) (config('billing.cron_token') ?: Settings::get('system.cron_token'));

        return [
            'Version' => config('billing.version'),
            'PHP' => PHP_VERSION,
            'Database' => config('database.default'),
            'Queue' => config('queue.default'),
            'Cache' => config('cache.default'),
            'Plugins folder' => config('billing.plugins_path'),
            'cron_url' => $token ? url('/cron/'.$token) : null,
            'cli_cron' => '* * * * * '.PHP_BINARY.' '.base_path('artisan').' schedule:run >> /dev/null 2>&1',
        ];
    }
}
