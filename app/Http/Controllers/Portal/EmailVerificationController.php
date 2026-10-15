<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Security\EmailVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The link in the verification email, and sending it again. */
class EmailVerificationController extends Controller
{
    public function __construct(private EmailVerification $verification) {}

    /** Works signed in or not, since the link may be opened on another device. */
    public function verify(Client $client, string $hash): RedirectResponse
    {
        $route = auth('client')->check() ? 'portal.dashboard' : 'portal.login';

        if (! $this->verification->verify($client, $hash)) {
            return redirect()->route($route)->withErrors(['email' => 'That link is for an email address the account no longer uses. Send a new one from the portal.']);
        }

        return redirect()->route($route)->with('status', 'Thanks, your email address is confirmed.');
    }

    public function resend(Request $request): RedirectResponse
    {
        $client = $request->user('client');

        if ($client->hasVerifiedEmail()) {
            return back()->with('status', 'Your email address is already confirmed.');
        }

        $this->verification->send($client);

        return back()->with('status', "We've sent a new link to {$client->email}.");
    }
}
