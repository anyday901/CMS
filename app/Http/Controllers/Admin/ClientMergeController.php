<?php

namespace App\Http\Controllers\Admin;

use App\Clients\ClientMerger;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/** Merges a duplicate client account into another: find the other client, check what moves, confirm. */
class ClientMergeController extends Controller
{
    public function show(Request $request, Client $client): View
    {
        $query = trim((string) $request->query('q'));
        $into = $request->filled('into') ? Client::find($request->integer('into')) : null;

        $matches = $query === '' ? collect() : Client::whereKeyNot($client->id)
            ->where(function ($q) use ($query) {
                if (ctype_digit($query)) {
                    $q->whereKey((int) $query);

                    return;
                }
                // Every word in the name, company or email, so "jane doe" finds Jane Doe.
                foreach (preg_split('/\s+/', $query) as $word) {
                    $like = '%'.addcslashes($word, '%_\\').'%';
                    $q->where(fn ($q) => $q->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                        ->orWhere('company', 'like', $like)->orWhere('email', 'like', $like));
                }
            })
            ->orderBy('last_name')->orderBy('first_name')->limit(20)->get();

        return view('admin.clients.merge', [
            'client' => $client,
            'query' => $query,
            'matches' => $matches,
            'into' => $into,
            'problem' => $into ? ClientMerger::problem($client, $into) : null,
            'counts' => ClientMerger::counts($client),
        ]);
    }

    public function store(Request $request, Client $client, ClientMerger $merger): RedirectResponse
    {
        $data = $request->validate([
            'into' => ['required', 'integer', 'exists:clients,id'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Tick the box to confirm the merge.']);

        $into = Client::findOrFail($data['into']);

        try {
            $merger->merge($client, $into, $request->boolean('keep_as_contact'));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['into' => $e->getMessage()]);
        }

        return redirect()->route('admin.clients.show', $into)->with('status', "Client #{$client->id} ({$client->fullName()}) is merged into this account.");
    }
}
