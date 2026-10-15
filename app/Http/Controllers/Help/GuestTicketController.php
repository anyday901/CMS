<?php

namespace App\Http\Controllers\Help;

use App\Enums\TicketPriority;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\CustomField;
use App\Models\Department;
use App\Models\InboundEmail;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketFeedback;
use App\Tickets\Sender;
use App\Tickets\SpamFilter;
use App\Tickets\TicketDesk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tickets from people without a client account: the contact form, and the
 * page a guest reaches through the private link they're emailed.
 *
 * The form never shows the new ticket straight away. The link goes to the
 * address that was typed in, so only whoever reads that inbox can follow the
 * ticket, and nobody can read replies meant for someone else's address.
 */
class GuestTicketController extends Controller
{
    /** Hidden form field people never fill in; bots that fill every field give themselves away. */
    private const HONEYPOT = 'website';

    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user('client') !== null || ! config('tickets.guest_tickets')) {
            return redirect()->route('portal.tickets.create', $request->only('department'));
        }

        $departments = Department::active()->orderBy('name')->get();
        $department = $departments->count() === 1 ? $departments->first() : $departments->firstWhere('id', $request->integer('department'));

        return view('help.tickets.create', [
            'departments' => $departments,
            'department' => $department,
            'fields' => $department ? CustomField::forTicket($department->id)->where('admin_only', false)->get() : collect(),
        ]);
    }

    public function store(Request $request, TicketDesk $desk, SpamFilter $spam): View|RedirectResponse
    {
        if (! config('tickets.guest_tickets')) {
            return redirect()->route('portal.tickets.create');
        }

        $fields = CustomField::forTicket($request->integer('department_id'))->where('admin_only', false)->get();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:250'],
            'email' => ['required', 'email', 'max:250'],
            'department_id' => ['required', Rule::exists('departments', 'id')->where('active', true)],
            'subject' => ['required', 'string', 'max:250'],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'body' => ['required', 'string', 'max:'.TicketDesk::MAX_BODY],
        ] + TicketDesk::uploadRules() + CustomField::rules($fields), [], CustomField::attributeNames($fields));
        $email = strtolower(trim($data['email']));
        $limit = (int) config('tickets.spam.new_tickets_per_hour');

        $blocked = match (true) {
            filled($request->input(self::HONEYPOT)) => 'the hidden field bots fill in was filled in',
            ($reason = $spam->check($email, $data['subject'], $data['body'])) !== null => $reason,
            $limit > 0 && Ticket::whereNull('client_id')->where('guest_email', $email)->where('created_at', '>=', now()->subHour())->count() >= $limit => "{$email} already opened {$limit} tickets in the last hour",
            default => null,
        };

        // Spam gets the same answer as a real ticket, so there's nothing to learn from trying.
        if ($blocked !== null) {
            InboundEmail::create(['from' => Str::limit($email, 250, ''), 'subject' => Str::limit($data['subject'], 250, ''), 'outcome' => InboundEmail::SPAM, 'reason' => Str::limit("contact form: {$blocked}", 250, '')]);

            return view('help.tickets.sent', ['email' => $email]);
        }

        $ticket = $desk->openForGuest(
            new Sender($email, $data['name']),
            Department::findOrFail($data['department_id']),
            $data['subject'],
            $data['body'],
            TicketPriority::from($data['priority']),
            TicketDesk::fromUploads($request->file('attachments')),
        );
        $ticket->update(['custom_fields' => CustomField::merge($fields, (array) $request->input('custom_fields', []), null)]);

        return view('help.tickets.sent', ['email' => $email]);
    }

    public function show(int $ticket, string $key): View|RedirectResponse
    {
        $found = $this->find($ticket, $key);

        if ($found->id !== $ticket) {
            return redirect()->to($found->guestUrl())->with('status', "Ticket #{$ticket} was merged into this one.");
        }

        $found->load(['department', 'feedback', 'messages' => fn ($query) => $query->where('internal', false)->with(['user', 'client', 'attachments'])]);

        return view('help.tickets.show', ['ticket' => $found, 'key' => $found->access_key, 'fields' => $found->customFieldDefinitions(clientView: true)]);
    }

    public function reply(Request $request, int $ticket, string $key, TicketDesk $desk): RedirectResponse
    {
        $ticket = $this->find($ticket, $key);
        $request->validate(['body' => ['required', 'string', 'max:'.TicketDesk::MAX_BODY]] + TicketDesk::uploadRules());

        $desk->clientReply($ticket, $request->input('body'), TicketDesk::fromUploads($request->file('attachments')));

        return redirect()->to($ticket->guestUrl())->with('status', 'Reply sent.');
    }

    public function close(int $ticket, string $key, TicketDesk $desk): RedirectResponse
    {
        $ticket = $this->find($ticket, $key);
        $desk->close($ticket);

        return redirect()->to($ticket->guestUrl())->with('status', 'Ticket closed. Reply any time to reopen it.');
    }

    /** The guest's rating of the support on a closed ticket. Once per ticket. */
    public function feedback(Request $request, int $ticket, string $key): RedirectResponse
    {
        $ticket = $this->find($ticket, $key);
        abort_unless($ticket->wantsFeedback(), 403);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,'.TicketFeedback::MAX_RATING],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $ticket->feedback()->create(['client_id' => null, 'rating' => (int) $data['rating'], 'comment' => filled($data['comment'] ?? null) ? trim($data['comment']) : null]);
        Activity::record("Guest rated ticket #{$ticket->id} {$data['rating']} out of ".TicketFeedback::MAX_RATING, $ticket);

        return redirect()->to($ticket->guestUrl())->with('status', 'Thanks for your feedback.');
    }

    public function attachment(int $ticket, string $key, int $attachment): StreamedResponse
    {
        $ticket = $this->find($ticket, $key);
        $file = TicketAttachment::whereKey($attachment)
            ->whereHas('message', fn ($query) => $query->where('ticket_id', $ticket->id)->where('internal', false))
            ->firstOrFail();

        return Storage::disk('local')->download($file->path, $file->name, ['X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * The guest ticket the key opens, or the guest ticket it was merged into.
     * A ticket moved to a client account has no key, so its old link stops working.
     */
    private function find(int $id, string $key): Ticket
    {
        $ticket = Ticket::whereNull('client_id')->findOrFail($id);
        abort_unless($ticket->hasAccessKey($key), 404);

        if ($ticket->merged_into_id === null) {
            return $ticket;
        }

        $into = Ticket::whereNull('client_id')->findOrFail($ticket->merged_into_id);
        abort_unless($into->sameRequester($ticket), 404);

        return $into;
    }
}
