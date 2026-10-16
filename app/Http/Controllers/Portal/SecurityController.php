<?php

namespace App\Http\Controllers\Portal;

use App\Auth\PortalContact;
use App\Http\Controllers\Concerns\ManagesTwoFactor;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ClientContact;
use App\Models\LoginAttempt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** Your own login: two-factor authentication, recent logins, and a contact's password. */
class SecurityController extends Controller
{
    use ManagesTwoFactor;

    public function show(Request $request): View
    {
        $account = PortalContact::account();
        $client = $request->user('client');

        // The account holder sees every login to the account, contacts' too; a contact sees their own, on every account.
        $logins = $account instanceof ClientContact
            ? LoginAttempt::where('account_type', $account->getMorphClass())->whereIn('account_id', ClientContact::loginsFor($account->email)->select('id'))
            : $client->loginAttempts()->with('account');

        return view('portal.security', [
            'account' => $account,
            'contact' => $account instanceof ClientContact ? $account : null,
            'logins' => $logins->latest('created_at')->latest('id')->limit(20)->get(),
            'setupSecret' => $account->hasTwoFactor() ? null : $request->session()->get('two_factor_setup'),
        ]);
    }

    /** A contact changes their own password here; the account holder does it under Account. */
    public function password(Request $request): RedirectResponse
    {
        $contact = PortalContact::current();
        abort_if($contact === null, 404);

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);

        if (! Hash::check($data['current_password'], (string) $contact->password)) {
            return back()->withErrors(['current_password' => __("That isn't your current password.")]);
        }

        $contact->forceFill(['password' => $data['password']])->save();
        Activity::record('Changed their portal password', $contact->client);

        return back()->with('status', __('Your password is changed.'));
    }

    protected function twoFactorAccount(): Model
    {
        return PortalContact::account();
    }

    protected function twoFactorSubject(): ?Model
    {
        return Auth::guard('client')->user();
    }

    protected function twoFactorPage(): string
    {
        return route('portal.security.show');
    }
}
