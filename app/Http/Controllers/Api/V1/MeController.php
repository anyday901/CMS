<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;

/** The key making the request: a quick way to check a key works and what it can do. */
class MeController extends ApiController
{
    public function __invoke(): JsonResponse
    {
        $key = $this->key();

        return response()->json(['data' => [
            'id' => $key->id,
            'name' => $key->name,
            'prefix' => $key->prefix,
            'abilities' => array_values($key->abilities ?? []),
            'expires_at' => $key->expires_at?->toIso8601String(),
        ]]);
    }
}
