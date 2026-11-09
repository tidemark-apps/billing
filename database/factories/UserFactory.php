<?php

namespace Database\Factories;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => 'password',
            'role' => Role::Customer,
            'is_active' => true,
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => Role::Admin]);
    }

    public function staff(): static
    {
        return $this->state(['role' => Role::Staff]);
    }

    /** A customer login together with its client record. */
    public function withClient(array $client = []): static
    {
        return $this->state(function (array $attrs) use ($client) {
            $c = ClientFactory::new()->create(array_merge(['name' => $attrs['name'], 'email' => $attrs['email']], $client));

            return ['client_id' => $c->id];
        });
    }
}
