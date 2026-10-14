<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\CannedReply;
use App\Models\CustomField;
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
            'status' => in_array($request->query('status'), ['all', 'overdue', 'answered', 'on_hold', 'closed'], true) ? $request->query('status') : 'waiting',
            'department' => $request->integer('department') ?: null,
            'mine' => $request->boolean('mine'),
        ];

        $tickets = Ticket::query()
            ->with(['client', 'department', 'assignee'])
            ->when($filters['status'] === 'waiting', fn ($query) => $query->awaitingStaff())
            ->when($filters['status'] === 'overdue', fn ($query) => $query->overdue())
            ->when(in_array($filters['status'], ['answered', 'on_hold', 'closed'], true), fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['department'], fn ($query, $id) => $query->where('department_id', $id))
            ->when($filters['mine'], fn ($query) => $query->where('assigned_to', $request->user()->id))
            ->when(in_array($filters['status'], ['waiting', 'overdue'], true),
                fn ($query) => $query->orderByRaw(TicketPriority::orderSql())->oldest('last_reply_at'),
                fn ($query) => $query->latest('last_reply_at'))
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.tickets.index', [
            'tickets' => $tickets,
            'filters' => $filters,
            'departments' => Department::orderBy('name')->get(),
            'overdueCount' => Ticket::overdue()->count(),
        ]);
    }

    public function show(Request $request, Ticket $ticket): View
    {
        $ticket->load(['client', 'department', 'assignee', 'messages.user', 'messages.client', 'messages.attachments']);

        return view('admin.tickets.show', [
            'ticket' => $ticket,
            'cannedReplies' => CannedReply::orderBy('title')->get()
                ->map(fn (CannedReply $reply) => ['title' => $reply->title, 'body' => $reply->bodyFor($ticket, $request->user())]),
            'departments' => Department::orderBy('name')->get(),
            'staff' => User::orderBy('name')->get()->filter(fn (User $user) => $user->allows('manage-tickets')),
            'customFields' => $ticket->customFieldDefinitions(),
        ]);
    }

    /** Status, priority, department, assignee and custom fields. */
    public function update(Request $request, Ticket $ticket): RedirectResponse
    {
        $fields = CustomField::forTicket($request->integer('department_id'))->get();
        $data = $request->validate([
            'status' => ['required', Rule::enum(TicketStatus::class)],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'department_id' => ['required', 'exists:departments,id'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            // Staff can leave a required field empty, such as on a ticket opened by email.
        ] + collect(CustomField::rules($fields))->map(fn (array $rules) => array_map(fn ($rule) => match ($rule) {
            'required' => 'nullable',
            'accepted' => 'in:0,1',
            default => $rule,
        }, $rules))->all(), [], CustomField::attributeNames($fields));
        unset($data['custom_fields']);

        $ticket->update($data + ['custom_fields' => CustomField::merge($fields, (array) $request->input('custom_fields', []), $ticket->custom_fields)]);

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
