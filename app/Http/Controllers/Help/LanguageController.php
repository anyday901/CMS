<?php

namespace App\Http\Controllers\Help;

use App\Auth\PortalContact;
use App\Http\Controllers\Controller;
use App\Support\Impersonation;
use App\Support\Language;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Remembers the language a visitor picked for the portal, help pages and
 * articles. A signed-in client keeps it on their account, so their emails
 * come in it too.
 */
class LanguageController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['language' => ['required', 'string', Rule::in(Language::all())]]);
        $request->session()->put(Language::SESSION_KEY, $data['language']);

        // Staff looking around as the client don't change the client's own choice.
        $client = auth('client')->user();
        if ($client && ! Impersonation::active() && ! PortalContact::current()) {
            $client->update(['language' => $data['language'] === Language::main() ? null : $data['language']]);
        }

        return redirect()->back(fallback: route('kb.index'));
    }
}
