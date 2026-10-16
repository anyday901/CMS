<?php

namespace App\Http\Controllers\Portal;

use App\Auth\ContactRememberCookie;
use App\Auth\PortalContact;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ClientContact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** A contact whose login reaches several client accounts picks which one to work in. */
class AccountSwitchController extends Controller
{
    public function index(): View|RedirectResponse
    {
        $contact = PortalContact::current();

        if ($contact === null || ! $contact->hasOtherAccounts()) {
            return redirect()->route('portal.dashboard');
        }

        return view('portal.accounts', ['current' => $contact, 'accounts' => $contact->accounts()]);
    }

    public function store(Request $request, ClientContact $contact): RedirectResponse
    {
        $current = PortalContact::current();

        abort_unless($current !== null && $contact->portal_access && $contact->email === $current->email && $contact->client->canLogIn(), 404);

        if ($contact->isNot($current)) {
            Activity::record('Left the account for another one', $current->client);
            Auth::guard('client')->login($contact->client);
            PortalContact::forget();
            PortalContact::start($contact);
            $request->session()->regenerate();
            $contact->forceFill(['last_login_at' => now()])->save();
            ContactRememberCookie::switchTo($request, $contact);
            Activity::record('Switched to this account in the portal', $contact->client);
        }

        return redirect()->intended(route('portal.dashboard'))->with('status', __("You're now in :account.", ['account' => $contact->accountName()]));
    }
}
