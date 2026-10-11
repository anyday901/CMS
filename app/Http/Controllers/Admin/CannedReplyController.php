<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\CannedReply;
use App\Tickets\TicketDesk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Saved answers staff pick from when replying to a ticket. */
class CannedReplyController extends Controller
{
    public function index(): View
    {
        return view('admin.canned-replies.index', ['replies' => CannedReply::orderBy('title')->get(), 'reply' => new CannedReply]);
    }

    public function store(Request $request): RedirectResponse
    {
        $reply = CannedReply::create($this->validated($request));
        Activity::record("Added canned reply {$reply->title}", $reply);

        return redirect()->route('admin.canned-replies.index')->with('status', 'Canned reply added.');
    }

    public function edit(CannedReply $cannedReply): View
    {
        return view('admin.canned-replies.edit', ['reply' => $cannedReply]);
    }

    public function update(Request $request, CannedReply $cannedReply): RedirectResponse
    {
        $cannedReply->update($this->validated($request));
        Activity::record("Updated canned reply {$cannedReply->title}", $cannedReply);

        return redirect()->route('admin.canned-replies.index')->with('status', 'Canned reply updated.');
    }

    public function destroy(CannedReply $cannedReply): RedirectResponse
    {
        $cannedReply->delete();
        Activity::record("Deleted canned reply {$cannedReply->title}");

        return redirect()->route('admin.canned-replies.index')->with('status', 'Canned reply deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:'.TicketDesk::MAX_BODY],
        ]);
    }
}
