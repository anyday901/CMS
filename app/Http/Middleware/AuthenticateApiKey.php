<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request through the REST API when it carries a working key in
 * "Authorization: Bearer <key>", and keeps each key to a request limit per
 * minute. Marked as authenticating so it runs before route model binding,
 * and a request without a key can't find out which records exist.
 */
class AuthenticateApiKey implements AuthenticatesRequests
{
    /** Requests each key can make per minute. */
    public const PER_MINUTE = 120;

    public function handle(Request $request, Closure $next): Response
    {
        $key = ApiKey::findByToken($request->bearerToken());

        if ($key === null) {
            return self::refuse(401, 'Send a valid API key in the Authorization header, as "Bearer <key>".');
        }

        if ($key->isRevoked() || $key->isExpired()) {
            return self::refuse(401, $key->isRevoked() ? 'This API key has been revoked.' : 'This API key has expired.');
        }

        if (! $key->allowsIp($request->ip())) {
            return self::refuse(403, "This API key can't be used from {$request->ip()}.");
        }

        $limiter = "api-key:{$key->id}";
        if (RateLimiter::tooManyAttempts($limiter, self::PER_MINUTE)) {
            $retry = RateLimiter::availableIn($limiter);

            return self::refuse(429, "Too many requests. Try again in {$retry} seconds.")->withHeaders([
                'Retry-After' => $retry,
                'X-RateLimit-Limit' => self::PER_MINUTE,
                'X-RateLimit-Remaining' => 0,
            ]);
        }
        RateLimiter::hit($limiter, 60);

        $key->recordUse($request->ip());
        $request->attributes->set(ApiKey::REQUEST_ATTRIBUTE, $key);

        $response = $next($request);
        $response->headers->add([
            'X-RateLimit-Limit' => self::PER_MINUTE,
            'X-RateLimit-Remaining' => RateLimiter::remaining($limiter, self::PER_MINUTE),
        ]);

        return $response;
    }

    public static function refuse(int $status, string $message): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
