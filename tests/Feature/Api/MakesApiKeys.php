<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\User;

/** Makes REST API keys for tests and sends requests with them. */
trait MakesApiKeys
{
    /**
     * @param  list<string>|null  $abilities  Every ability when null.
     * @return array{0: ApiKey, 1: string}
     */
    protected function apiKey(?array $abilities = null, array $attributes = []): array
    {
        return ApiKey::generate($attributes + [
            'name' => 'Test key',
            'abilities' => $abilities ?? array_keys(ApiKey::ABILITIES),
            'user_id' => User::factory()->create(['name' => 'Key Maker'])->id,
        ]);
    }

    /** Sends API requests with a key that can do everything, and returns the key. */
    protected function withApiKey(?array $abilities = null, array $attributes = []): ApiKey
    {
        [$key, $plain] = $this->apiKey($abilities, $attributes);
        $this->withToken($plain);

        return $key;
    }
}
