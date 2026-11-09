<?php

namespace App\Listeners;

use App\Events\DomainEvent;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
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
            ? (new $resource($event->model))->resolve(request())
            : $event->model->toArray();

        return $event->context ? $data + ['context' => $event->context] : $data;
    }
}
