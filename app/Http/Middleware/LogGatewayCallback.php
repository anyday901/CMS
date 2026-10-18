<?php

namespace App\Http\Middleware;

use App\Payments\GatewayLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Adds every call to a gateway's webhook, return or confirmation route to
 * the gateway log, with the answer the app gave. Gateways, including ones
 * from plugins, get logged by putting LogGatewayCallback::using('key') on
 * their routes.
 */
class LogGatewayCallback
{
    public function __construct(private GatewayLogger $logger) {}

    /** The middleware string for a route, such as LogGatewayCallback::using('stripe'). */
    public static function using(string $gateway): string
    {
        return static::class.':'.$gateway;
    }

    public function handle(Request $request, Closure $next, string $gateway): Response
    {
        $response = $next($request);

        try {
            $this->logger->record($gateway, $request, $response);
        } catch (Throwable $e) {
            // The log must never turn away a payment.
            Log::warning('Could not write the gateway log', ['gateway' => $gateway, 'error' => $e->getMessage()]);
        }

        return $response;
    }
}
