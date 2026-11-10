<?php

namespace App\Http\Controllers\Admin;

use App\Events\DomainEvent;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\ApiToken;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\TwoFactor;
use App\Support\Abilities;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Admin screens for API tokens and outbound webhooks. (The REST endpoints themselves live in routes/api.php.) */
class ApiController extends Controller
{
    /** @var array<int, string> */
    public const RESOURCES = ['clients', 'products', 'services', 'invoices', 'quotes', 'payments', 'tax-rates', 'webhooks'];

    public function index(Request $request)
    {
        $webhooks = WebhookEndpoint::withCount('deliveries')->latest()->get();
        foreach ($webhooks as $w) {
            $w->setRelation('lastDelivery', $w->deliveries()->latest('id')->first());
        }

        return view('admin.api.index', [
            'tokens' => $request->user()->apiTokens()->latest()->get(),
            'webhooks' => $webhooks,
            'events' => DomainEvent::catalog(),
            'abilityCatalog' => Abilities::STAFF,
            'newToken' => session('new_token'),
            'newSecret' => session('new_secret'),
            'apiUrl' => url('/api/v1'),
        ]);
    }

    public function storeToken(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        if (app(TwoFactor::class)->staffMustEnrol($request->user())) {
            return redirect()->route('admin.account.security.index')->with('error', 'Two-factor authentication is required for staff before API tokens can be created. Set it up first.');
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'scope' => ['required', 'in:full,custom'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string'],
            'expires_in' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);
        $abilities = $data['scope'] === 'full' ? null : collect($data['abilities'] ?? [])->filter(fn ($a) => Abilities::isValid($a, 'staff'))->values()->all();
        [, $plain] = ApiToken::issue($request->user(), $data['name'], $abilities, isset($data['expires_in']) ? now()->addDays((int) $data['expires_in']) : null);
        Activity::log('api.token.created', null, 'Created API token "'.$data['name'].'"');

        return redirect()->route('admin.api.index')->with('new_token', $plain)->with('status', 'Token created. Copy it now: it is shown only once.');
    }

    public function destroyToken(Request $request, ApiToken $token): RedirectResponse
    {
        abort_unless($token->user_id === $request->user()->id || $request->user()->isAdmin(), 403);
        $token->delete();
        Activity::log('api.token.revoked', null, 'Revoked API token "'.$token->name.'"');

        return back()->with('status', 'Token revoked.');
    }

    public function storeWebhook(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $this->validated($request);
        $secret = 'whsec_'.Str::random(32);
        WebhookEndpoint::create($data + ['secret' => $secret, 'is_active' => true]);
        Activity::log('webhook.created', null, 'Added webhook '.$data['url']);

        return redirect()->to(route('admin.api.index').'#webhooks')->with('new_secret', $secret)->with('status', 'Webhook added. Copy the signing secret now: it is shown only once.');
    }

    public function showWebhook(WebhookEndpoint $webhook)
    {
        $deliveries = $webhook->deliveries()->latest('id')->limit(50)->get();

        return view('admin.api.webhook', ['webhook' => $webhook, 'deliveries' => $deliveries, 'events' => DomainEvent::catalog(), 'failures' => $deliveries->where('success', false)->count()]);
    }

    public function updateWebhook(Request $request, WebhookEndpoint $webhook): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $webhook->update($this->validated($request) + ['is_active' => $request->boolean('is_active')]);

        return back()->with('status', 'Webhook updated.');
    }

    public function destroyWebhook(Request $request, WebhookEndpoint $webhook): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $webhook->delete();

        return redirect()->to(route('admin.api.index').'#webhooks')->with('status', 'Webhook deleted.');
    }

    public function testWebhook(WebhookEndpoint $webhook): RedirectResponse
    {
        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $webhook->id,
            'event' => 'ping',
            'payload' => ['id' => (string) Str::uuid(), 'event' => 'ping', 'created_at' => now()->toIso8601String(), 'data' => ['message' => 'Hello from '.config('billing.name')]],
        ]);
        DeliverWebhook::dispatchSync($delivery->id);
        $delivery->refresh();

        return back()->with($delivery->success ? 'status' : 'error', $delivery->success ? 'Ping delivered (HTTP '.$delivery->status_code.').' : 'Ping failed: '.($delivery->response ?: 'no response'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $catalog = array_keys(DomainEvent::catalog());
        $data = $request->validate([
            'url' => ['required', 'url:http,https', 'max:500'],
            'description' => ['nullable', 'string', 'max:255'],
            'events' => ['nullable', 'array'],
            'events.*' => ['in:'.implode(',', $catalog)],
        ]);

        return ['url' => $data['url'], 'description' => $data['description'] ?? null, 'events' => ! empty($data['events']) ? array_values($data['events']) : ['*']];
    }
}
