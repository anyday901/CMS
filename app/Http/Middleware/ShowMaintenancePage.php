<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Maintenance mode (Settings > Maintenance): the portal, store and help pages
 * show a notice instead. The admin area, payment webhooks, ticket email, the
 * unsubscribe link, the installer and theme files keep working, and signed-in
 * staff can still look around the portal.
 */
class ShowMaintenancePage
{
    /** Paths that keep working in maintenance mode. */
    public const OPEN = ['admin', 'admin/*', 'webhooks/*', 'unsubscribe/*', 'install', 'install/*', 'theme-assets/*', 'branding/*', 'health', 'up'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('maintenance.enabled') || $request->is(...self::OPEN) || auth('web')->check()) {
            return $next($request);
        }

        return response()->view('maintenance', ['message' => config('maintenance.message')], 503)
            ->header('Retry-After', '600');
    }
}
