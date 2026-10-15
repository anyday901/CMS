<?php

namespace App\Http\Middleware;

use App\Auth\PortalContact;
use App\Auth\PortalLogin;
use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a client out as soon as they can no longer log in (for example, staff
 * closed the account), instead of honouring an existing session or
 * remember-me cookie. A contact is signed out too once their portal access
 * is taken away.
 */
class EnsureClientCanLogIn
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->user('client');

        if ($client && ! $client->canLogIn() && Impersonation::active()) {
            $client = Impersonation::stop();

            return redirect()->route('admin.clients.show', $client)
                ->withErrors(['client' => 'This account is closed, so you were signed out of its portal.']);
        }

        if ($client && (! $client->canLogIn() || (PortalContact::signedIn() && PortalContact::current() === null))) {
            PortalLogin::logout($request);

            return redirect()->route('portal.login')
                ->withErrors(['email' => 'This account can no longer sign in. Please contact support.']);
        }

        return $next($request);
    }
}
