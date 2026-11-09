<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 600, 3600];
    }

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::with('endpoint')->find($this->deliveryId);
        if (! $delivery || ! $delivery->endpoint || ! $delivery->endpoint->is_active) {
            return;
        }
        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $delivery->endpoint->secret);

        try {
            $response = Http::timeout(10)->withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'Tidemark-Billing-Webhook/1.0',
                'X-Billing-Event' => $delivery->event,
                'X-Billing-Delivery' => (string) $delivery->payload['id'],
                'X-Billing-Timestamp' => (string) $timestamp,
                'X-Billing-Signature' => 'sha256='.$signature,
            ])->withBody($body, 'application/json')->post($delivery->endpoint->url);
            $delivery->update([
                'attempts' => $delivery->attempts + 1,
                'status_code' => $response->status(),
                'response' => mb_substr($response->body(), 0, 1000),
                'success' => $response->successful(),
                'delivered_at' => now(),
            ]);
            if (! $response->successful()) {
                $this->release($this->backoff()[min($this->attempts() - 1, 2)]);
            }
        } catch (\Throwable $e) {
            $delivery->update(['attempts' => $delivery->attempts + 1, 'success' => false, 'response' => mb_substr($e->getMessage(), 0, 1000)]);
            $this->release($this->backoff()[min($this->attempts() - 1, 2)]);
        }
    }
}
