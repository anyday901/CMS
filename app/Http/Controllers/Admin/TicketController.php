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
use App\Models\TicketTag;
use App\Models\User;
use App\Tickets\TicketDesk;
use App\Tickets\TicketMerger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
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
            'tag' => TicketTag::normalize([(string) $request->query('tag')])[0] ?? null,
        ];

        $tickets = Ticket::listed()
            ->with(['client', 'department', 'assignee', 'tags'])
            ->when($filters['status'] === 'waiting', fn ($query) => $query->awaitingStaff())
            ->when($filters['status'] === 'overdue', fn ($query) => $query->overdue())
            ->when(in_array($filters['status'], ['answered', 'on_hold', 'closed'], true), fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['department'], fn ($query, $id) => $query->where('department_id', $id))
            ->when($filters['mine'], fn ($query) => $query->where('assigned_to', $request->user()->id))
            ->when($filters['tag'], fn ($query, $tag) => $query->whereHas('tags', fn ($tags) => $tags->where('name', $tag)))
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
            'tags' => TicketTag::has('tickets')->orderBy('name')->pluck('name'),
            'overdueCount' => Ticket::overdue()->count(),
        ]);
    }

    public function show(Request $request, Ticket $ticket): View|RedirectResponse
    {
        if ($ticket->merged_into_id !== null) {
            return redirect()->route('admin.tickets.show', $ticket->merged_into_id)->with('status', "Ticket #{$ticket->id} was merged into this one.");
        }

        $ticket->load(['client', 'department', 'assignee', 'tags', 'feedback', 'messages.user', 'messages.client', 'messages.attachments']);

        return view('admin.tickets.show', [
            'ticket' => $ticket,
            'cannedReplies' => CannedReply::orderBy('title')->get()
                ->map(fn (CannedReply $reply) => ['title' => $reply->title, 'body' => $reply->bodyFor($ticket, $request->user())]),
            'departments' => Department::orderBy('name')->get(),
            'staff' => User::orderBy('name')->get()->filter(fn (User $user) => $user->allows('manage-tickets')),
            'customFields' => $ticket->customFieldDefinitions(),
            'allTags' => TicketTag::orderBy('name')->pluck('name'),
            // Other tickets of the client, to merge into this one.
            'otherTickets' => $ticket->client->tickets()->listed()->whereKeyNot($ticket->id)->latest('last_reply_at')->limit(30)->get(),
        ]);
    }

    /** Status, priority, department, assignee, tags and custom fields. */
    public function update(Request $request, Ticket $ticket, TicketDesk $desk): RedirectResponse
    {
        $fields = CustomField::forTicket($request->integer('department_id'))->get();
        $data = $request->validate([
            'status' => ['required', Rule::enum(TicketStatus::class)],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'department_id' => ['required', 'exists:departments,id'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'tags' => ['nullable', 'string', 'max:1000'],
            // Staff can leave a required field empty, such as on a ticket opened by email.
        ] + collect(CustomField::rules($fields))->map(fn (array $rules) => array_map(fn ($rule) => match ($rule) {
            'required' => 'nullable',
            'accepted' => 'in:0,1',
            default => $rule,
        }, $rules))->all(), [], CustomField::attributeNames($fields));
        unset($data['custom_fields'], $data['tags']);
        $tagsBefore = $ticket->tags()->pluck('name')->all();

        $ticket->update($data + ['custom_fields' => CustomField::merge($fields, (array) $request->input('custom_fields', []), $ticket->custom_fields)]);
        $ticket->syncTags(TicketTag::parse($request->input('tags')));
        $changed = array_keys(array_diff_key($ticket->getChanges(), ['updated_at' => 1, 'response_due_at' => 1, 'closed_at' => 1]));

        if ($ticket->tags()->pluck('name')->all() !== $tagsBefore) {
            $changed[] = 'tags';
        }

        if ($changed !== []) {
            Activity::record("Updated ticket #{$ticket->id}", $ticket, ['changed' => $changed]);
        }

        if ($ticket->wasChanged('status') && $ticket->status === TicketStatus::Closed) {
            $desk->requestFeedback($ticket);
        }

        return back()->with('status', 'Ticket updated.');
    }

    /** Merges other tickets of the same client into this one. */
    public function merge(Request $request, Ticket $ticket, TicketMerger $merger): RedirectResponse
    {
        $data = $request->validate(['tickets' => ['required', 'array', 'max:30'], 'tickets.*' => ['integer']], ['tickets.required' => 'Pick the tickets to merge into this one.']);

        try {
            $merger->merge($ticket, Ticket::with('tags')->findMany($data['tickets']), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['tickets' => $e->getMessage()]);
        }

        return redirect()->route('admin.tickets.show', $ticket)->with('status', 'Tickets merged.');
    }

    /** Moves the picked messages to a new ticket for the same client. */
    public function split(Request $request, Ticket $ticket, TicketMerger $merger): RedirectResponse
    {
        $data = $request->validate([
            'messages' => ['required', 'array'],
            'messages.*' => ['integer'],
            'split_subject' => ['required', 'string', 'max:250'],
            'split_department_id' => ['required', 'exists:departments,id'],
            'split_priority' => ['required', Rule::enum(TicketPriority::class)],
        ], ['messages.required' => 'Tick the messages to move to the new ticket.'], ['split_subject' => 'subject', 'split_department_id' => 'department', 'split_priority' => 'priority']);

        try {
            $new = $merger->split($ticket->load('tags'), array_map('intval', $data['messages']), [
                'subject' => $data['split_subject'],
                'department_id' => (int) $data['split_department_id'],
                'priority' => TicketPriority::from($data['split_priority']),
            ], $request->user(), $request->boolean('notify'));
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['messages' => $e->getMessage()]);
        }

        return redirect()->route('admin.tickets.show', $new)->with('status', "Split into ticket #{$new->id}.");
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
