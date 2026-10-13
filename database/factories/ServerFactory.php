<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Server> */
class ServerFactory extends Factory
{
    public function definition(): array
    {
        $name = 'server'.fake()->unique()->numberBetween(1, 9999);

        return [
            'name' => $name,
            'module' => 'cpanel',
            'hostname' => "{$name}.example.test",
            'username' => 'root',
            'api_token' => 'TOKEN123',
            'max_accounts' => 200,
        ];
    }
}
