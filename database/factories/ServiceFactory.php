<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Service> */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'product_id' => Product::factory(),
            'billing_cycle' => BillingCycle::Monthly,
            'recurring_amount' => 1000,
            'status' => ServiceStatus::Active,
            'registration_date' => '2026-01-01',
            'next_due_date' => '2026-11-01',
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => ServiceStatus::Pending]);
    }
}
