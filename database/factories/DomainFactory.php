<?php

namespace Database\Factories;

use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Domain> */
class DomainFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => fake()->unique()->domainWord().'.com',
            'type' => Domain::REGISTER,
            'status' => DomainStatus::Active,
            'years' => 1,
            'recurring_amount' => 1500,
            'registration_date' => '2026-01-01',
            'expiry_date' => '2027-01-01',
            'next_due_date' => '2027-01-01',
            'auto_renew' => true,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => DomainStatus::Pending, 'registration_date' => null, 'expiry_date' => null]);
    }
}
