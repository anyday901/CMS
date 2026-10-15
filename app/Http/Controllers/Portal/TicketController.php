<?php

namespace App\Http\Controllers\Portal;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\CustomField;
use App\Models\Department;
use App\Models\KbArticle;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketFeedback;
use App\Support\Markdown;
use App\Tickets\TicketDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketController extends Controller
{
    public function index(Request $request): View
    {
        $tickets = $request->user('client')->tickets()->listed()
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

    public function show(Request $request, int $ticket): View|RedirectResponse
    {
        $found = $this->find($request, $ticket);

        if ($found->id !== $ticket) {
            return redirect()->route('portal.tickets.show', $found)->with('status', "Ticket #{$ticket} was merged into this one.");
        }

        $found->load(['department', 'feedback', 'messages' => fn ($query) => $query->where('internal', false)->with(['user', 'client', 'attachments'])]);

        return view('portal.tickets.show', ['ticket' => $found, 'fields' => $found->customFieldDefinitions(clientView: true)]);
    }

    /**
     * Knowledge base articles that might answer the ticket being typed.
     * Returned as JSON for the new ticket form.
     */
    public function suggest(Request $request): JsonResponse
    {
        $text = trim((string) $request->query('subject').' '.Str::limit((string) $request->query('body'), 300, ''));

        return response()->json(['articles' => KbArticle::suggest($text)->map(fn (KbArticle $article) => [
            'title' => $article->title,
            'url' => route('kb.article', $article),
            'excerpt' => Markdown::excerpt($article->body, 140),
        ])]);
    }

    /** The client's rating of the support on a closed ticket. Once per ticket. */
    public function feedback(Request $request, int $ticket): RedirectResponse
    {
        $ticket = $this->find($request, $ticket);
        abort_unless($ticket->wantsFeedback(), 403);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,'.TicketFeedback::MAX_RATING],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $ticket->feedback()->create(['client_id' => $ticket->client_id, 'rating' => (int) $data['rating'], 'comment' => filled($data['comment'] ?? null) ? trim($data['comment']) : null]);
        Activity::record("Rated ticket #{$ticket->id} {$data['rating']} out of ".TicketFeedback::MAX_RATING, $ticket);

        return redirect()->route('portal.tickets.show', $ticket)->with('status', 'Thanks for your feedback.');
    }

    public function reply(Request $request, int $ticket, TicketDesk $desk): RedirectResponse
    {
        $ticket = $this->find($request, $ticket);
        $request->validate(['body' => ['required', 'string', 'max:'.TicketDesk::MAX_BODY]] + TicketDesk::uploadRules());

        $desk->clientReply($ticket, $request->input('body'), TicketDesk::fromUploads($request->file('attachments')));

        return redirect()->route('portal.tickets.show', $ticket)->with('status', 'Reply sent.');
    }

    public function close(Request $request, int $ticket, TicketDesk $desk): RedirectResponse
    {
        $ticket = $this->find($request, $ticket);
        $desk->close($ticket);

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

    /** The client's ticket, or the ticket it was merged into. */
    private function find(Request $request, int $id): Ticket
    {
        $ticket = $request->user('client')->tickets()->findOrFail($id);

        return $ticket->merged_into_id !== null ? $request->user('client')->tickets()->findOrFail($ticket->merged_into_id) : $ticket;
    }
}
