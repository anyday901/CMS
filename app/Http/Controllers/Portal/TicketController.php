<?php

namespace App\Http\Controllers\Portal;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\CustomField;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Tickets\TicketDesk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketController extends Controller
{
    public function index(Request $request): View
    {
        $tickets = $request->user('client')->tickets()
            ->with('department')
            ->orderByRaw('case when status = ? then 1 else 0 end', [TicketStatus::Closed->value])
            ->latest('last_reply_at')
            ->paginate(20);

        return view('portal.tickets.index', compact('tickets'));
    }

    /** First the department, then the form with that department's fields. */
    public function create(Request $request): View
    {
        $departments = Department::active()->orderBy('name')->get();
        $department = $departments->count() === 1 ? $departments->first() : $departments->firstWhere('id', $request->integer('department'));

        return view('portal.tickets.create', [
            'departments' => $departments,
            'department' => $department,
            'fields' => $department ? CustomField::forTicket($department->id)->where('admin_only', false)->get() : collect(),
        ]);
    }

    public function store(Request $request, TicketDesk $desk): RedirectResponse
    {
        $fields = CustomField::forTicket($request->integer('department_id'))->where('admin_only', false)->get();
        $data = $request->validate([
            'department_id' => ['required', Rule::exists('departments', 'id')->where('active', true)],
            'subject' => ['required', 'string', 'max:250'],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'body' => ['required', 'string', 'max:'.TicketDesk::MAX_BODY],
        ] + TicketDesk::uploadRules() + CustomField::rules($fields), [], CustomField::attributeNames($fields));

        $ticket = $desk->open(
            $request->user('client'),
            Department::findOrFail($data['department_id']),
            $data['subject'],
            $data['body'],
            TicketPriority::from($data['priority']),
            TicketDesk::fromUploads($request->file('attachments')),
        );
        $ticket->update(['custom_fields' => CustomField::merge($fields, (array) $request->input('custom_fields', []), null)]);

        return redirect()->route('portal.tickets.show', $ticket)->with('status', "Ticket #{$ticket->id} opened. We'll be in touch soon.");
    }

    public function show(Request $request, int $ticket): View
    {
        $ticket = $this->find($request, $ticket);
        $ticket->load(['department', 'messages' => fn ($query) => $query->where('internal', false)->with(['user', 'client', 'attachments'])]);

        return view('portal.tickets.show', ['ticket' => $ticket, 'fields' => $ticket->customFieldDefinitions(clientView: true)]);
    }

    public function reply(Request $request, int $ticket, TicketDesk $desk): RedirectResponse
    {
        $ticket = $this->find($request, $ticket);
        $request->validate(['body' => ['required', 'string', 'max:'.TicketDesk::MAX_BODY]] + TicketDesk::uploadRules());

        $desk->clientReply($ticket, $request->input('body'), TicketDesk::fromUploads($request->file('attachments')));

        return redirect()->route('portal.tickets.show', $ticket)->with('status', 'Reply sent.');
    }

    public function close(Request $request, int $ticket): RedirectResponse
    {
        $ticket = $this->find($request, $ticket);
        $ticket->update(['status' => TicketStatus::Closed]);
        Activity::record("Closed ticket #{$ticket->id}", $ticket);

        return redirect()->route('portal.tickets.show', $ticket)->with('status', 'Ticket closed. Reply any time to reopen it.');
    }

    public function attachment(Request $request, int $ticket, int $attachment): StreamedResponse
    {
        $ticket = $this->find($request, $ticket);
        $file = TicketAttachment::whereKey($attachment)
            ->whereHas('message', fn ($query) => $query->where('ticket_id', $ticket->id)->where('internal', false))
            ->firstOrFail();

        return Storage::disk('local')->download($file->path, $file->name, ['X-Content-Type-Options' => 'nosniff']);
    }

    private function find(Request $request, int $id): Ticket
    {
        return $request->user('client')->tickets()->findOrFail($id);
    }
}
