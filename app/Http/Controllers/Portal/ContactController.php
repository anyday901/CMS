<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ClientContact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Clients add the other people on their account under Account. */
class ContactController extends Controller
{
    public function create(): View
    {
        return view('portal.contacts.edit', ['contact' => new ClientContact]);
    }

    public function store(Request $request): RedirectResponse
    {
        $client = $request->user('client');
        $data = $request->validate(ClientContact::rules($client));
        $contact = $client->contacts()->create(ClientContact::attributesFrom($data, $request));
        Activity::record("Added contact {$contact->fullName()}", $client);

        return redirect()->route('portal.account.edit')->with('status', 'Contact added.');
    }

    public function edit(Request $request, ClientContact $contact): View
    {
        $this->authorizeContact($request, $contact);

        return view('portal.contacts.edit', compact('contact'));
    }

    public function update(Request $request, ClientContact $contact): RedirectResponse
    {
        $this->authorizeContact($request, $contact);
        $data = $request->validate(ClientContact::rules($contact->client, $contact));
        $contact->update(ClientContact::attributesFrom($data, $request));
        Activity::record("Updated contact {$contact->fullName()}", $contact->client);

        return redirect()->route('portal.account.edit')->with('status', 'Contact updated.');
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
