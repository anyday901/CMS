<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** When the Security page requires it, staff set up two-factor authentication before anything else. */
class RequireStaffTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if (! config('security.staff_two_factor') || $user === null || $user->hasTwoFactor() || $request->routeIs('admin.account.*', 'admin.logout')) {
            return $next($request);
        }

        return redirect()->route('admin.account.show')
            ->withErrors(['two_factor' => 'Set up two-factor authentication to keep using the admin area.']);
    }
}
