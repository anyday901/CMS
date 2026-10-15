<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the request through only if its API key has the ability, such as
 * clients:read. Runs with the authentication, before route model binding,
 * so a key without the ability can't find out which records exist.
 */
class EnsureApiKeyCan implements AuthenticatesRequests
{
    /** The middleware string for a route, such as EnsureApiKeyCan::using('clients:read'). */
    public static function using(string $ability): string
    {
        return static::class.':'.$ability;
    }

    public function handle(Request $request, Closure $next, string $ability): Response
    {
        if (! ApiKey::current()?->allows($ability)) {
            return AuthenticateApiKey::refuse(403, "This API key doesn't have the {$ability} ability.");
        }

        return $next($request);
    }
}
