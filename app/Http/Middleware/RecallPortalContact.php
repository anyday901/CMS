<?php

namespace App\Http\Middleware;

use App\Auth\ContactRememberCookie;
use App\Auth\PortalContact;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Signs a contact back in from their remember-me cookie when their session has ended. */
class RecallPortalContact
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->cookies->has(ContactRememberCookie::NAME) && ! $request->is('admin', 'admin/*', 'webhooks/*') && ! Auth::guard('client')->check()) {
            $contact = ContactRememberCookie::recall($request);

            if ($contact === null) {
                ContactRememberCookie::forget();
            } else {
                Auth::guard('client')->login($contact->client);
                PortalContact::start($contact);
                $request->session()->regenerate();
            }
        }

        return $next($request);
    }
}
