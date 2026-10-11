<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientContact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Extra people on a client's account. */
class ClientContactController extends Controller
{
    public function create(Client $client): View
    {
        return view('admin.contacts.edit', ['contact' => new ClientContact, 'client' => $client]);
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate(ClientContact::rules($client));
        $contact = $client->contacts()->create(ClientContact::attributesFrom($data, $request));
        Activity::record("Added contact {$contact->fullName()}", $client);

        return redirect()->route('admin.clients.show', $client)->with('status', 'Contact added.');
    }

    public function edit(ClientContact $contact): View
    {
        return view('admin.contacts.edit', ['contact' => $contact, 'client' => $contact->client]);
    }

    public function update(Request $request, ClientContact $contact): RedirectResponse
    {
        $data = $request->validate(ClientContact::rules($contact->client, $contact));
        $contact->update(ClientContact::attributesFrom($data, $request));
        Activity::record("Updated contact {$contact->fullName()}", $contact->client);

        return redirect()->route('admin.clients.show', $contact->client)->with('status', 'Contact updated.');
    }

    public function destroy(ClientContact $contact): RedirectResponse
    {
        $contact->delete();
        Activity::record("Removed contact {$contact->fullName()}", $contact->client);

        return redirect()->route('admin.clients.show', $contact->client)->with('status', 'Contact removed.');
    }
}
