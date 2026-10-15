<?php

namespace App\Http\Controllers\Help;

use App\Http\Controllers\Controller;
use App\Support\Language;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Remembers the language a visitor picked for knowledge base articles and announcements. */
class LanguageController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['language' => ['required', 'string', Rule::in(Language::all())]]);
        $request->session()->put(Language::SESSION_KEY, $data['language']);

        return redirect()->back(fallback: route('kb.index'));
    }
}
