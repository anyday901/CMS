<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientContact;
use App\Privacy\ClientAnonymiser;
use App\Privacy\ClientDataExport;
use App\Security\EmailVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A client's login security and personal data: verification, two-factor resets, GDPR export and anonymising. */
class ClientPrivacyController extends Controller
{
    public function export(Client $client, ClientDataExport $export): Response
    {
        Activity::record('Exported the client\'s personal data', $client);

        return $export->download($client);
    }

    public function anonymise(Request $request, Client $client, ClientAnonymiser $anonymiser): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Tick the box to confirm. Anonymising can\'t be undone.']);

        if ($problem = $anonymiser->problem($client)) {
            return back()->withErrors(['anonymise' => $problem]);
        }

        $anonymiser->anonymise($client);

        return redirect()->route('admin.clients.show', [$client, 'tab' => 'security'])->with('status', 'The client is anonymised. Their invoices and payments are kept without their personal details.');
    }

    public function verifyEmail(Client $client): RedirectResponse
    {
        $client->forceFill(['email_verified_at' => now()])->save();
        Activity::record("Marked {$client->email} as verified", $client);

        return back()->with('status', 'The email address is marked as verified.');
    }

    public function sendVerification(Client $client, EmailVerification $verification): RedirectResponse
    {
        $verification->send($client);
        Activity::record('Sent an email verification link', $client);

        return back()->with('status', "A verification link is on its way to {$client->email}.");
    }

    public function resetTwoFactor(Client $client): RedirectResponse
    {
        $client->disableTwoFactor();
        Activity::record('Turned off two-factor authentication for the client', $client);

        return back()->with('status', 'Two-factor authentication is off for this client. They can turn it on again under Security in the portal.');
    }

    public function resetContactTwoFactor(ClientContact $contact): RedirectResponse
    {
        $contact->disableTwoFactor();
        Activity::record("Turned off two-factor authentication for contact {$contact->fullName()}", $contact->client);

        return back()->with('status', "Two-factor authentication is off for {$contact->fullName()}.");
    }
}
