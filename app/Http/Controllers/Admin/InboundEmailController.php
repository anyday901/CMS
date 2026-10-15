<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InboundEmail;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The emails piped in for tickets, and contact form messages caught as spam, with what became of each. */
class InboundEmailController extends Controller
{
    public function __invoke(Request $request): View
    {
        $outcome = array_key_exists((string) $request->query('outcome'), InboundEmail::OUTCOMES) ? (string) $request->query('outcome') : null;
        $search = trim((string) $request->query('q'));

        return view('admin.tickets.inbound', [
            'outcome' => $outcome,
            'search' => $search,
            'spamCount' => InboundEmail::where('outcome', InboundEmail::SPAM)->where('created_at', '>=', now()->subDays(7))->count(),
            'emails' => InboundEmail::query()
                ->when($outcome, fn ($query, $value) => $query->where('outcome', $value))
                ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                    ->where('from', 'like', '%'.addcslashes($search, '\\%_').'%')
                    ->orWhere('subject', 'like', '%'.addcslashes($search, '\\%_').'%')))
                ->latest()->latest('id')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }
}
