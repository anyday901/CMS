<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Client> */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'currency' => 'USD',
            'status' => 'active',
        ];
    }

    /** A client who hasn't clicked the link in their verification email yet. */
    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }
}
