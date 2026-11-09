<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'company_name' => fake()->boolean(60) ? fake()->company() : null,
            'email' => fake()->unique()->safeEmail(),
            'address_1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'postcode' => fake()->postcode(),
            'country' => 'US',
            'currency' => 'USD',
        ];
    }
}
