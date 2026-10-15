<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Staff notes on a client's page. Pinned ones show at the top. */
class ClientNoteController extends Controller
{
    private const SECTION = '#notes';

    public function store(Request $request, Client $client): RedirectResponse
    {
        $note = $client->clientNotes()->make($this->validated($request));
        $note->user()->associate($request->user());
        $note->save();
        Activity::record('Added a note', $client);

        return redirect()->to(route('admin.clients.show', $client).self::SECTION)->with('status', 'Note added.');
    }

    public function update(Request $request, ClientNote $note): RedirectResponse
    {
        $note->update($this->validated($request));
        Activity::record('Edited a note', $note->client);

        return redirect()->to(route('admin.clients.show', $note->client).self::SECTION)->with('status', 'Note saved.');
    }

    public function destroy(ClientNote $note): RedirectResponse
    {
        $note->delete();
        Activity::record('Deleted a note', $note->client);

        return redirect()->to(route('admin.clients.show', $note->client).self::SECTION)->with('status', 'Note deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate(['body' => ['required', 'string', 'max:10000']]) + ['pinned' => $request->boolean('pinned')];
    }
}
