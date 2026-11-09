<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Support\FieldSchema;
use App\Http\Controllers\Api\Support\SettingsSchema;
use App\Plugins\GatewayRegistry;
use App\Services\BillingRunner;
use App\Support\Fields;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Settings screens as an API. Administrators only (as in the UI). Secrets (SMTP password, gateway keys) are
 * write-only: responses say whether one is stored (`has_value`), never what it is. Updates are partial: send only the
 * settings you want to change; a blank secret keeps the stored one; null/"" on other settings resets them to the default.
 */
class SettingsController extends ApiController
{
    public function __construct(private readonly GatewayRegistry $gateways) {}

    public function index(Request $request)
    {
        $this->requireAdmin($request, 'Only administrators can read settings.');

        return response()->json(['data' => collect(SettingsSchema::SECTIONS)->map(fn ($s) => ['section' => $s, 'editable' => $s !== 'system'])->values()]);
    }

    public function show(Request $request, string $section)
    {
        $this->requireAdmin($request, 'Only administrators can read settings.');

        return response()->json(['data' => $this->present($section)]);
    }

    /**
     * general / billing / email: {"settings": {"company.name": "Acme", "mail.password": "..."}}
     * payments: {"gateways": {"stripe": {"enabled": true, "title": "Card", "secret_key": "..."}}}
     */
    public function update(Request $request, string $section)
    {
        $this->requireAdmin($request, 'Only administrators can change settings.');
        abort_if($section === 'system', 404);

        $section === 'payments' ? $this->updateGateways($request) : $this->updateSection($request, $section);

        return response()->json(['data' => $this->present($section)]);
    }

    /** Send a test email to the calling administrator. */
    public function testEmail(Request $request)
    {
        $this->requireAdmin($request, 'Only administrators can change settings.');
        try {
            Mail::raw('If you can read this, outgoing email from '.config('billing.name').' works.', fn ($m) => $m->to($request->user()->email)->subject('Test email'));

            return response()->json(['data' => ['success' => true, 'message' => 'Test email sent to '.$request->user()->email.'.']]);
        } catch (\Throwable $e) {
            return response()->json(['data' => ['success' => false, 'message' => 'Could not send: '.$e->getMessage()]]);
        }
    }

    /** Generate a new cron URL token. The new URL is returned once; the old one stops working. */
    public function regenerateCronToken(Request $request)
    {
        $this->requireAdmin($request, 'Only administrators can change settings.');
        if (config('billing.cron_token')) {
            throw new \DomainException('The cron token is fixed by BILLING_CRON_TOKEN in the environment. Change it there.');
        }
        Settings::set('system.cron_token', Str::random(40), true);
        $this->audit('settings.updated', null, 'Regenerated the cron token');

        return response()->json(['data' => ['cron_url' => url('/cron/'.$this->cronToken())]]);
    }

    /** Run the daily billing tasks now (renewal invoices, reminders, suspensions...). */
    public function runBilling(Request $request, BillingRunner $runner)
    {
        $this->requireAdmin($request, 'Only administrators can run billing tasks.');
        $result = $runner->run();
        $this->audit('billing.run', null, 'Ran billing tasks manually', $result);

        return response()->json(['data' => $result]);
    }

    // ---- reading ------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function present(string $section): array
    {
        return match (true) {
            $section === 'payments' => ['section' => 'payments', 'gateways' => $this->presentGateways()],
            $section === 'system' => ['section' => 'system', 'info' => $this->systemInfo()],
            in_array($section, SettingsSchema::EDITABLE, true) => ['section' => $section, 'groups' => array_map(fn ($g) => [
                'title' => $g['title'],
                'description' => $g['description'],
                'fields' => FieldSchema::describe($g['fields'], fn ($key) => Settings::get($key)),
            ], SettingsSchema::groups($section))],
            default => abort(404),
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function presentGateways(): array
    {
        return collect($this->gateways->all())->map(fn ($g, $key) => [
            'key' => $key,
            'label' => $g->label(),
            'description' => $g->description(),
            'enabled' => Settings::bool("gateway.$key.enabled"),
            'configured' => $g->isConfigured(),
            'title' => Settings::get("gateway.$key.title"),
            'fields' => FieldSchema::describe($g->fields(), fn ($field) => Settings::get("gateway.$key.$field")),
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function systemInfo(): array
    {
        return [
            'version' => config('billing.version'),
            'php' => PHP_VERSION,
            'database' => config('database.default'),
            'queue' => config('queue.default'),
            'cache' => config('cache.default'),
            'plugins_path' => config('billing.plugins_path'),
            'cron_url_configured' => $this->cronToken() !== '', // the URL itself contains a secret: see POST /settings/system/cron-token
            'cli_cron' => '* * * * * '.PHP_BINARY.' '.base_path('artisan').' schedule:run >> /dev/null 2>&1',
        ];
    }

    private function cronToken(): string
    {
        return (string) (config('billing.cron_token') ?: Settings::get('system.cron_token'));
    }

    // ---- writing ------------------------------------------------------------------------------------------------

    private function updateSection(Request $request, string $section): void
    {
        abort_unless(in_array($section, SettingsSchema::EDITABLE, true), 404);
        $fields = SettingsSchema::fields($section);
        $sent = $request->input('settings');
        FieldSchema::rejectUnknown($fields, $sent, 'settings');

        // The web form cannot carry dots in field names and the shared validator (Fields::rules) is keyed by field name,
        // so validate under "__" names and map the error keys back.
        $form = fn (string $key) => str_replace('.', '__', $key);
        $formFields = array_map(fn ($f) => ['key' => $form($f['key'])] + $f, array_values(array_filter($fields, fn ($f) => array_key_exists($f['key'], $sent))));
        $input = [];
        foreach ($sent as $key => $value) {
            $input[$form($key)] = $value;
        }
        $names = [];
        foreach ($formFields as $f) {
            $names['s.'.$f['key']] = str_replace('__', '.', $f['key']);
        }
        $validator = Validator::make(['s' => $input], Fields::rules($formFields, 's.'), [], $names);
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->toArray() as $attr => $messages) {
                $errors['settings.'.str_replace('__', '.', substr($attr, 2))] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }

        foreach ($formFields as $f) {
            $key = str_replace('__', '.', $f['key']);
            $collected = FieldSchema::collectPresent([$f], $input);
            if (! array_key_exists($f['key'], $collected)) {
                continue; // a password sent blank keeps the stored one
            }
            $value = $collected[$f['key']];
            if (($f['type'] ?? 'text') === 'checkbox') {
                Settings::set($key, $value ? 'true' : 'false');
            } elseif ($value === null || $value === '') {
                Settings::forget($key);
            } else {
                Settings::set($key, $value, ($f['type'] ?? 'text') === 'password');
            }
        }
        $this->audit('settings.updated', null, "Updated $section settings");
    }

    private function updateGateways(Request $request): void
    {
        $sent = $request->input('gateways');
        if (! is_array($sent)) {
            throw ValidationException::withMessages(['gateways' => 'Must be an object keyed by gateway.']);
        }
        $unknown = array_diff(array_keys($sent), array_keys($this->gateways->all()));
        if ($unknown) {
            throw ValidationException::withMessages(['gateways' => 'Unknown gateway: '.implode(', ', $unknown).'. Enable the plugin first.']);
        }

        foreach ($sent as $key => $input) {
            $gateway = $this->gateways->get($key);
            $fields = $gateway->fields();
            $input = is_array($input) ? $input : [];
            FieldSchema::rejectUnknown(array_merge($fields, [['key' => 'enabled'], ['key' => 'title']]), $input, "gateways.$key");
            $request->validate(Fields::rules($fields, "gateways.$key.") + ["gateways.$key.title" => ['nullable', 'string', 'max:100'], "gateways.$key.enabled" => ['nullable', 'boolean']]);

            if (array_key_exists('enabled', $input)) {
                Settings::set("gateway.$key.enabled", filter_var($input['enabled'], FILTER_VALIDATE_BOOL) ? 'true' : 'false');
            }
            if (array_key_exists('title', $input)) {
                ($t = trim((string) $input['title'])) !== '' ? Settings::set("gateway.$key.title", $t) : Settings::forget("gateway.$key.title");
            }
            $secrets = Fields::secretKeys($fields);
            foreach (FieldSchema::collectPresent($fields, $input) as $field => $value) {
                if ($value === null || $value === '') {
                    Settings::forget("gateway.$key.$field");
                } else {
                    Settings::set("gateway.$key.$field", is_bool($value) ? ($value ? 'true' : 'false') : $value, in_array($field, $secrets, true));
                }
            }
        }
        $this->audit('settings.updated', null, 'Updated payment gateway settings');
    }
}
