<?php

namespace App\Services;

use App\Enums\Role;
use App\Events\ClientCreated;
use App\Models\Client;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

class Registration
{
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * Create a client with its first login. Used by sign-up, the API and the admin.
     *
     * @param  array<string, mixed>  $client  name, email, company_name?, phone?, address_1…, country?, tax_id?, currency?
     * @return array{client: Client, user: User}
     */
    public function createClient(array $client, string $password, bool $welcome = true): array
    {
        $result = DB::transaction(function () use ($client, $password) {
            $client['currency'] ??= Settings::get('billing.currency', config('billing.currency'));
            $model = Client::create($client);
            $user = User::create([
                'name' => $client['name'],
                'email' => $client['email'],
                'password' => $password,
                'role' => Role::Customer,
                'client_id' => $model->id,
            ]);

            return ['client' => $model, 'user' => $user];
        });
        event(new ClientCreated($result['client']));
        if ($welcome) {
            $this->notifier->welcome($result['user']);
        }

        return $result;
    }
}
