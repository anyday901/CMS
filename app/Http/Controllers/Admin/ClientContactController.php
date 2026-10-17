<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientContact;
use App\Security\ContactInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Extra people on a client's account. */
class ClientContactController extends Controller
{
    public function __construct(private ContactInvitation $invitation) {}

    public function create(Client $client): View
    {
        return view('admin.contacts.edit', ['contact' => new ClientContact, 'client' => $client]);
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate(ClientContact::rules($client, request: $request));
        $contact = $client->contacts()->create(ClientContact::attributesFrom($data, $request));
        Activity::record("Added contact {$contact->fullName()}", $client);

        return redirect()->route('admin.clients.show', [$client, 'tab' => 'contacts'])->with('status', $this->status($this->invitation->sendIfNew($contact, true), 'added', $contact));
    }

    public function edit(ClientContact $contact): View
    {
        return view('admin.contacts.edit', ['contact' => $contact, 'client' => $contact->client]);
    }

    public function update(Request $request, ClientContact $contact): RedirectResponse
    {
        $data = $request->validate(ClientContact::rules($contact->client, $contact, $request));
        $contact->update(ClientContact::attributesFrom($data, $request));
        Activity::record("Updated contact {$contact->fullName()}", $contact->client);

        return redirect()->route('admin.clients.show', [$contact->client, 'tab' => 'contacts'])->with('status', $this->status($this->invitation->sendIfNew($contact, $contact->wasChanged(['portal_access', 'email'])), 'updated', $contact));
    }

    public function resendInvite(ClientContact $contact): RedirectResponse
    {
        abort_unless($contact->portal_access, 404);
        $this->invitation->send($contact);
        Activity::record("Sent contact {$contact->fullName()} a portal password link", $contact->client);

        return back()->with('status', "A link to choose a password is on its way to {$contact->email}.");
    }

    public function destroy(ClientContact $contact): RedirectResponse
    {
        $contact->delete();
        Activity::record("Removed contact {$contact->fullName()}", $contact->client);

        return redirect()->route('admin.clients.show', [$contact->client, 'tab' => 'contacts'])->with('status', 'Contact removed.');
    }

    private function status(?string $sent, string $done, ClientContact $contact): string
    {
        return match ($sent) {
            ContactInvitation::INVITED => "Contact {$done}, and invited to choose a portal password.",
            ContactInvitation::ADDED => "Contact {$done}. {$contact->email} already signs in to another account, so they use that login here too and have been told.",
            default => "Contact {$done}.",
        };
    }
}
