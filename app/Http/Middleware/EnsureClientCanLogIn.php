<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a client out as soon as they can no longer log in (for example, staff
 * closed the account), instead of honouring an existing session or
 * remember-me cookie.
 */
class EnsureClientCanLogIn
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->user('client');

        if ($client && ! $client->canLogIn()) {
            Auth::guard('client')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('portal.login')
                ->withErrors(['email' => 'This account can no longer sign in. Please contact support.']);
        }

        return $next($request);
    }
}
