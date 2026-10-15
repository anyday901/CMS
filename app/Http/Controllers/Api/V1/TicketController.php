<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Resources\TicketMessageResource;
use App\Http\Resources\TicketResource;
use App\Models\Client;
use App\Models\Department;
use App\Models\Ticket;
use App\Tickets\TicketDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tickets through the REST API. They go through the ticket desk like the
 * portal and admin area, so the client is emailed and staff are alerted.
 * Staff replies are sent in the name of the staff member who made the key.
 */
class TicketController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
        ]);

        $tickets = Ticket::listed()->with('department')
            ->when($filters['client_id'] ?? null, fn ($query, $id) => $query->where('client_id', $id))
            ->when($filters['department_id'] ?? null, fn ($query, $id) => $query->where('department_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('last_reply_at')
            ->latest('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return TicketResource::collection($tickets);
    }

    public function show(Ticket $ticket): TicketResource
    {
        return TicketResource::make($this->withMessages($ticket));
    }

    public function store(Request $request, TicketDesk $desk): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('active', true)],
            'subject' => ['required', 'string', 'max:250'],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
            'body' => ['required', 'string', 'max:'.TicketDesk::MAX_BODY],
        ]);

        $ticket = $desk->open(
            Client::findOrFail($data['client_id']),
            Department::findOrFail($data['department_id']),
            $data['subject'],
            $data['body'],
            TicketPriority::tryFrom($data['priority'] ?? '') ?? TicketPriority::Medium,
        );

        return TicketResource::make($this->withMessages($ticket))->response()->setStatusCode(201);
    }

    /** A reply from staff (the default), or from the client or guest who opened the ticket. */
    public function reply(Request $request, Ticket $ticket, TicketDesk $desk): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.TicketDesk::MAX_BODY],
            'as' => ['nullable', Rule::in(['staff', 'client'])],
            'internal' => ['boolean'],
        ]);

        if (($data['as'] ?? 'staff') === 'client') {
            $message = $desk->clientReply($ticket, $data['body']);
        } else {
            $staff = $this->key()->user ?? throw ValidationException::withMessages([
                'as' => 'The staff member who made this API key no longer exists, so it can\'t reply as staff. Make a new key.',
            ]);
            $message = $desk->staffReply($ticket, $staff, $data['body'], $request->boolean('internal'));
        }

        return TicketMessageResource::make($message->load(['user', 'client']))->additional([
            'ticket' => TicketResource::make($ticket->refresh()->load('department')),
        ])->response()->setStatusCode(201);
    }

    /** The ticket with the messages the client can see; internal notes stay in the admin area. */
    private function withMessages(Ticket $ticket): Ticket
    {
        return $ticket->load([
            'department',
            'messages' => fn ($query) => $query->where('internal', false)->with(['user', 'client', 'attachments']),
        ]);
    }
}
