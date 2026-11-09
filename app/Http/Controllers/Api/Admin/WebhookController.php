<?php

namespace App\Http\Controllers\Api\Admin;

use App\Events\DomainEvent;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\WebhookDeliveryResource;
use App\Http\Resources\WebhookEndpointResource;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookController extends ApiController
{
    public function index(Request $request)
    {
        return $this->paginate(WebhookEndpoint::orderBy('id'), WebhookEndpointResource::class, $request);
    }

    /** The signing secret is generated here and returned in this response only. */
    public function store(Request $request)
    {
        $this->requireAdmin($request, 'Only administrators can manage webhooks.');
        $data = $this->validated($request);
        $secret = 'whsec_'.Str::random(32);
        $endpoint = WebhookEndpoint::create($data + ['secret' => $secret, 'is_active' => $data['is_active'] ?? true]);
        $this->audit('webhook.created', null, 'Added webhook '.$data['url']);
        $body = (new WebhookEndpointResource($endpoint->refresh()))->resolve($request) + ['secret' => $secret];

        return response()->json(['data' => $body], 201);
    }

    public function show(WebhookEndpoint $webhook): WebhookEndpointResource
    {
        return new WebhookEndpointResource($webhook);
    }

    public function update(Request $request, WebhookEndpoint $webhook): WebhookEndpointResource
    {
        $this->requireAdmin($request, 'Only administrators can manage webhooks.');
        $webhook->update($this->validated($request, true));
        $this->audit('webhook.updated', null, 'Updated webhook '.$webhook->url);

        return new WebhookEndpointResource($webhook->refresh());
    }

    public function destroy(Request $request, WebhookEndpoint $webhook)
    {
        $this->requireAdmin($request, 'Only administrators can manage webhooks.');
        $webhook->delete();
        $this->audit('webhook.deleted', null, 'Deleted webhook '.$webhook->url);

        return response()->noContent();
    }

    /** Send a "ping" event to the endpoint right now. */
    public function test(WebhookEndpoint $webhook)
    {
        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $webhook->id,
            'event' => 'ping',
            'payload' => ['id' => (string) Str::uuid(), 'event' => 'ping', 'created_at' => now()->toIso8601String(), 'data' => ['message' => 'This is a test event.']],
        ]);
        DeliverWebhook::dispatch($delivery->id);

        return (new WebhookDeliveryResource($delivery->refresh()))->response()->setStatusCode(202);
    }

    public function deliveries(Request $request, WebhookEndpoint $webhook)
    {
        return $this->paginate($webhook->deliveries(), WebhookDeliveryResource::class, $request);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $data = $request->validate([
            'url' => [$partial ? 'sometimes' : 'required', 'url:http,https', 'max:500'],
            'description' => ['nullable', 'string', 'max:255'],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', function ($attr, $value, $fail) {
                if ($value !== '*' && ! array_key_exists($value, DomainEvent::catalog())) {
                    $fail("Unknown event \"$value\".");
                }
            }],
            'is_active' => ['nullable', 'boolean'],
        ]);
        if (array_key_exists('events', $data) && empty($data['events'])) {
            $data['events'] = ['*'];
        }

        return $data;
    }
}
