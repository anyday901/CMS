<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Staff opening the client portal as one of their clients, and coming back. */
class ImpersonationController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        if (! $client->canLogIn()) {
            return back()->withErrors(['client' => 'This account is closed, so it can\'t be used to sign in to the portal.']);
        }

        Impersonation::start($request->user('web'), $client);

        return redirect()->route('portal.dashboard');
    }

    public function destroy(): RedirectResponse
    {
        if (! Impersonation::active()) {
            return redirect()->route('portal.dashboard');
        }

        $client = Impersonation::stop();

        return redirect()->route('admin.clients.show', $client)->with('status', "You've left {$client->fullName()}'s portal.");
    }
}
