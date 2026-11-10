<?php

namespace App\Listeners;

use App\Enums\Role;
use App\Events\DomainEvent;
use App\Jobs\DeliverWebhook;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Turns every DomainEvent into signed outbound webhook deliveries for the endpoints subscribed to it. */
class QueueWebhooks
{
    /** @param  array<int, mixed>  $payload */
    public function handle(string $eventName, array $payload): void
    {
        $event = $payload[0] ?? null;
        if (! $event instanceof DomainEvent) {
            return;
        }
        try {
            $endpoints = WebhookEndpoint::where('is_active', true)->get()->filter(fn (WebhookEndpoint $e) => $e->listensTo($event->name()));
            if ($endpoints->isEmpty()) {
                return;
            }
            $data = $this->serialize($event);
            foreach ($endpoints as $endpoint) {
                $delivery = WebhookDelivery::create([
                    'webhook_endpoint_id' => $endpoint->id,
                    'event' => $event->name(),
                    'payload' => [
                        'id' => (string) Str::uuid(),
                        'event' => $event->name(),
                        'created_at' => now()->toIso8601String(),
                        'data' => $data,
                    ],
                ]);
                DeliverWebhook::dispatch($delivery->id);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return array<string, mixed> */
    private function serialize(DomainEvent $event): array
    {
        $resource = 'App\\Http\\Resources\\'.class_basename($event->model).'Resource';
        $data = class_exists($resource)
            ? (new $resource($event->model))->resolve($this->audience($event))
            : $event->model->toArray();

        return $event->context ? $data + ['context' => $event->context] : $data;
    }

    /**
     * Webhooks go to endpoints the administrator configured, so they always carry the staff view of a resource, whatever
     * triggered the event (a customer's API call, a staff action, a queue job). Ticket threads are never included.
     */
    private function audience(DomainEvent $event): Request
    {
        if (! $event->model instanceof Ticket) {
            return request();
        }
        $request = Request::create('/');
        $request->setUserResolver(fn () => (new User)->forceFill(['role' => Role::Admin]));

        return $request;
    }
}
