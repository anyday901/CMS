<?php

namespace App\Http\Middleware;

use App\Auth\PortalContact;
use App\Models\ClientContact;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a contact signed in to the portal to the parts of the account they're
 * allowed: billing, orders, services or support. "owner" pages are for the
 * account holder only. The account holder can open everything.
 */
class EnsureContactCan
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (PortalContact::allows($permission)) {
            return $next($request);
        }

        $message = $permission === PortalContact::OWNER
            ? __('Only the account holder can open that page.')
            : __("Your login doesn't include :permission. Ask the account holder if you need it.", ['permission' => self::area($permission)]);

        if ($request->expectsJson()) {
            abort(403, $message);
        }

        return redirect()->route('portal.dashboard')->withErrors(['permission' => $message]);
    }

    /** The part of the portal a permission opens, such as "invoices". */
    private static function area(string $permission): string
    {
        return match ($permission) {
            'billing' => __('invoices'),
            'orders' => __('orders'),
            'services' => __('services'),
            'support' => __('support'),
            default => strtolower(strtok(ClientContact::PERMISSIONS[$permission] ?? $permission, ':')),
        };
    }
}
