<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ClientContact;
use App\Security\ContactInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Clients add the other people on their account under Account, and choose what each can do in the portal. */
class ContactController extends Controller
{
    public function __construct(private ContactInvitation $invitation) {}

    public function create(): View
    {
        return view('portal.contacts.edit', ['contact' => new ClientContact]);
    }

    public function store(Request $request): RedirectResponse
    {
        $client = $request->user('client');
        $data = $request->validate(ClientContact::rules($client, request: $request));
        $contact = $client->contacts()->create(ClientContact::attributesFrom($data, $request));
        Activity::record("Added contact {$contact->fullName()}", $client);

        return redirect()->route('portal.account.edit')->with('status', ContactInvitation::message($this->invitation->sendIfNew($contact, true), 'Contact added.', $contact));
    }

    public function edit(Request $request, ClientContact $contact): View
    {
        $this->authorizeContact($request, $contact);

        return view('portal.contacts.edit', compact('contact'));
    }

    public function update(Request $request, ClientContact $contact): RedirectResponse
    {
        $this->authorizeContact($request, $contact);
        $data = $request->validate(ClientContact::rules($contact->client, $contact, $request));
        $contact->update(ClientContact::attributesFrom($data, $request));
        Activity::record("Updated contact {$contact->fullName()}", $contact->client);

        return redirect()->route('portal.account.edit')->with('status', ContactInvitation::message($this->invitation->sendIfNew($contact, $contact->wasChanged(['portal_access', 'email'])), 'Contact updated.', $contact));
    }

    public function resendInvite(Request $request, ClientContact $contact): RedirectResponse
    {
        $this->authorizeContact($request, $contact);
        abort_unless($contact->portal_access, 404);
        $this->invitation->send($contact);
        Activity::record("Sent contact {$contact->fullName()} a portal password link", $contact->client);

        return back()->with('status', "A link to choose a password is on its way to {$contact->email}.");
    }

    public function destroy(Request $request, ClientContact $contact): RedirectResponse
    {
        $this->authorizeContact($request, $contact);
        $contact->delete();
        Activity::record("Removed contact {$contact->fullName()}", $contact->client);

        return redirect()->route('portal.account.edit')->with('status', 'Contact removed.');
    }

    private function authorizeContact(Request $request, ClientContact $contact): void
    {
        abort_unless($contact->client_id === $request->user('client')->id, 404);
    }
}
