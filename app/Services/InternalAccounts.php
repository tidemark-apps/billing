<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Support\Facades\DB;

/**
 * Internal accounts: your own team, test customers, partners on the house. They are left out of every revenue figure and
 * count in reports, and every service they have costs nothing. The price a service would normally have is remembered
 * (services.internal_original_amount) so that taking the flag off puts it back.
 */
class InternalAccounts
{
    public static function is(?Client $client): bool
    {
        return (bool) $client?->is_internal;
    }

    /**
     * The amount fields to store on a new service: free for internal clients, with the real price remembered.
     *
     * @return array{amount: int, internal_original_amount?: int}
     */
    public static function pricing(?Client $client, int $amount): array
    {
        return self::is($client) ? ['amount' => 0, 'internal_original_amount' => $amount] : ['amount' => $amount];
    }

    /**
     * Fields to store when staff edit a service's price. For an internal client the price stays 0; a non-zero figure
     * entered is remembered as the price they would pay, and 0 (what the form shows) changes nothing.
     *
     * @return array<string, int>
     */
    public static function reprice(Service $service, int $submitted): array
    {
        if (! self::is($service->client)) {
            return ['amount' => $submitted];
        }

        return $submitted === 0 ? [] : ['amount' => 0, 'internal_original_amount' => $submitted];
    }

    /** Mark or unmark a client, repricing every one of their services. Open invoices are left as they are. */
    public function set(Client $client, bool $internal, ?User $by = null): Client
    {
        if ($client->is_internal === $internal) {
            return $client;
        }
        DB::transaction(function () use ($client, $internal) {
            $client->update(['is_internal' => $internal]);
            Service::withTrashed()->where('client_id', $client->id)->each(function (Service $s) use ($internal) {
                if ($internal) {
                    $s->update(['internal_original_amount' => $s->internal_original_amount ?? $s->amount, 'amount' => 0]);
                } elseif ($s->internal_original_amount !== null) {
                    $s->update(['amount' => $s->internal_original_amount, 'internal_original_amount' => null]);
                }
            });
        });
        Activity::log($internal ? 'client.marked_internal' : 'client.unmarked_internal', $client, $client->displayName().($internal ? ' marked internal; service prices set to zero' : ' is no longer internal; service prices restored'));

        return $client->refresh();
    }
}
