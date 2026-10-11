<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Tickets\TicketDesk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketController extends Controller
{
    /** Tickets waiting on staff come first by default, most urgent then longest waiting. */
    public function index(Request $request): View
    {
        $filters = [
            'status' => in_array($request->query('status'), ['all', 'answered', 'on_hold', 'closed'], true) ? $request->query('status') : 'waiting',
            'department' => $request->integer('department') ?: null,
            'mine' => $request->boolean('mine'),
        ];

        $tickets = Ticket::query()
            ->with(['client', 'department', 'assignee'])
            ->when($filters['status'] === 'waiting', fn ($query) => $query->awaitingStaff())
            ->when(in_array($filters['status'], ['answered', 'on_hold', 'closed'], true), fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['department'], fn ($query, $id) => $query->where('department_id', $id))
            ->when($filters['mine'], fn ($query) => $query->where('assigned_to', $request->user()->id))
            ->when($filters['status'] === 'waiting',
                fn ($query) => $query->orderByRaw(TicketPriority::orderSql())->oldest('last_reply_at'),
                fn ($query) => $query->latest('last_reply_at'))
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.tickets.index', [
            'tickets' => $tickets,
            'filters' => $filters,
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function show(Ticket $ticket): View
    {
        $ticket->load(['client', 'department', 'assignee', 'messages.user', 'messages.client', 'messages.attachments']);

        return view('admin.tickets.show', [
            'ticket' => $ticket,
            'departments' => Department::orderBy('name')->get(),
            'staff' => User::orderBy('name')->get()->filter(fn (User $user) => $user->allows('manage-tickets')),
        ]);
    }

    /** Status, priority, department and assignee. */
    public function update(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(TicketStatus::class)],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'department_id' => ['required', 'exists:departments,id'],
            'assigned_to' => ['nullable', 'exists:users,id'],
        ]);

        $ticket->update($data);

        if ($ticket->wasChanged()) {
            Activity::record("Updated ticket #{$ticket->id}", $ticket, ['changed' => array_keys(array_diff_key($ticket->getChanges(), ['updated_at' => 1]))]);
        }

        return back()->with('status', 'Ticket updated.');
    }

    public function reply(Request $request, Ticket $ticket, TicketDesk $desk): RedirectResponse
    {
        $request->validate(['body' => ['required', 'string', 'max:'.TicketDesk::MAX_BODY]] + TicketDesk::uploadRules());
        $internal = $request->boolean('internal');

        $desk->staffReply($ticket, $request->user(), $request->input('body'), $internal, TicketDesk::fromUploads($request->file('attachments')));

        return redirect()->route('admin.tickets.show', $ticket)->with('status', $internal ? 'Note added.' : 'Reply sent.');
    }

    public function attachment(Ticket $ticket, TicketAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->message->ticket_id === $ticket->id, 404);

        return Storage::disk('local')->download($attachment->path, $attachment->name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
