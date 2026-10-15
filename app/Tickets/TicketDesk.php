<?php

namespace App\Tickets;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Mail\ClientMailer;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\StaffAlert;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Opens tickets and adds replies, from the portal, the staff area or piped
 * email, so every route stores files, emails the client and alerts staff the
 * same way.
 */
class TicketDesk
{
    /** File types clients and staff can attach. */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'log', 'csv', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods'];

    public const MAX_FILES = 5;

    public const MAX_KB = 10240;

    /** Longest message body kept, so a huge email can't fill the database. */
    public const MAX_BODY = 60000;

    public function __construct(private ClientMailer $mailer) {}

    /**
     * Validation rules for an attachments[] upload field.
     *
     * @return array<string, list<string>>
     */
    public static function uploadRules(): array
    {
        return [
            'attachments' => ['array', 'max:'.self::MAX_FILES],
            'attachments.*' => ['file', 'max:'.self::MAX_KB, 'extensions:'.implode(',', self::EXTENSIONS)],
        ];
    }

    /**
     * @param  array<int, UploadedFile>|null  $uploads
     * @return list<array{name: string, mime: ?string, contents: string}>
     */
    public static function fromUploads(?array $uploads): array
    {
        return collect($uploads ?? [])->map(fn (UploadedFile $file) => [
            'name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'contents' => $file->get(),
        ])->values()->all();
    }

    /** @param  list<array{name: string, mime: ?string, contents: string}>  $files */
    public function open(Client $client, Department $department, string $subject, string $body, TicketPriority $priority = TicketPriority::Medium, array $files = [], ?EmailOrigin $email = null): Ticket
    {
        $ticket = DB::transaction(function () use ($client, $department, $subject, $body, $priority, $files, $email) {
            $ticket = Ticket::create([
                'client_id' => $client->id,
                'department_id' => $department->id,
                'subject' => Str::limit(trim($subject), 250, ''),
                'status' => TicketStatus::Open,
                'priority' => $priority,
                'last_reply_at' => now(),
            ]);
            $this->addMessage($ticket, ['client_id' => $client->id] + ($email?->attributes() ?? []), $body, $files);
            Activity::record("Opened ticket #{$ticket->id}: {$ticket->subject}", $ticket, $email ? ['via' => 'email'] : []);

            return $ticket;
        });

        $this->sendOpened($ticket);
        $this->alertStaff($ticket, "New ticket #{$ticket->id}: {$ticket->subject}", "{$client->fullName()} opened a ticket in {$department->name} ({$priority->label()} priority).");

        return $ticket;
    }

    /**
     * A ticket from someone without a client account, by email or the
     * contact form. They follow it through the private link they're emailed.
     *
     * @param  list<array{name: string, mime: ?string, contents: string}>  $files
     */
    public function openForGuest(string $name, string $email, Department $department, string $subject, string $body, TicketPriority $priority = TicketPriority::Medium, array $files = [], ?EmailOrigin $origin = null): Ticket
    {
        $name = Str::limit(trim($name), 250, '') ?: Str::before($email, '@');

        $ticket = DB::transaction(function () use ($name, $email, $department, $subject, $body, $priority, $files, $origin) {
            $ticket = Ticket::create([
                'guest_name' => $name,
                'guest_email' => Str::limit(strtolower(trim($email)), 250, ''),
                'access_key' => Str::random(40),
                'department_id' => $department->id,
                'subject' => Str::limit(trim($subject), 250, ''),
                'status' => TicketStatus::Open,
                'priority' => $priority,
                'last_reply_at' => now(),
            ]);
            $this->addMessage($ticket, ['guest' => true, 'author_name' => $name] + ($origin?->attributes() ?? []), $body, $files);
            Activity::record("Opened ticket #{$ticket->id} from guest {$ticket->guest_email}: {$ticket->subject}", $ticket, $origin ? ['via' => 'email'] : []);

            return $ticket;
        });

        $this->sendOpened($ticket);
        $this->alertStaff($ticket, "New ticket #{$ticket->id}: {$ticket->subject}", "{$name} ({$ticket->guest_email}), who has no account, opened a ticket in {$department->name} ({$priority->label()} priority).");

        return $ticket;
    }

    /** Emails whoever opened the ticket its number, so their replies thread onto it. */
    public function sendOpened(Ticket $ticket): void
    {
        $this->mailer->sendForTicket($ticket, 'ticket_opened');
    }

    /**
     * A reply from the ticket's client or guest. It reopens a closed ticket and puts it back in the staff queue.
     *
     * @param  list<array{name: string, mime: ?string, contents: string}>  $files
     */
    public function clientReply(Ticket $ticket, string $body, array $files = [], ?EmailOrigin $email = null): TicketMessage
    {
        $message = DB::transaction(function () use ($ticket, $body, $files, $email) {
            $author = $ticket->isGuest() ? ['guest' => true, 'author_name' => $ticket->guest_name] : ['client_id' => $ticket->client_id];
            $message = $this->addMessage($ticket, $author + ($email?->attributes() ?? []), $body, $files);
            $ticket->update(['status' => TicketStatus::CustomerReply, 'last_reply_at' => now(), 'escalations' => null]);
            Activity::record(($ticket->isGuest() ? 'Guest' : 'Client')." replied to ticket #{$ticket->id}", $ticket, $email ? ['via' => 'email'] : []);

            return $message;
        });

        $this->alertStaff($ticket, "Reply on ticket #{$ticket->id}: {$ticket->subject}", "{$ticket->requesterName()} replied:", Str::limit($message->body, 500));

        return $message;
    }

    /**
     * A staff reply, emailed to the client, or an internal note, which isn't.
     *
     * @param  list<array{name: string, mime: ?string, contents: string}>  $files
     */
    public function staffReply(Ticket $ticket, User $user, string $body, bool $internal = false, array $files = []): TicketMessage
    {
        $message = DB::transaction(function () use ($ticket, $user, $body, $internal, $files) {
            $message = $this->addMessage($ticket, ['user_id' => $user->id, 'internal' => $internal], $body, $files);

            if (! $internal) {
                $ticket->update([
                    'status' => TicketStatus::Answered,
                    'last_reply_at' => now(),
                    'first_response_at' => $ticket->first_response_at ?? now(),
                    'escalations' => null,
                ]);
            }
            Activity::record(($internal ? 'Added a note to' : 'Replied to')." ticket #{$ticket->id}", $ticket);

            return $message;
        });

        if (! $internal) {
            $this->mailer->sendForTicket($ticket, 'ticket_reply', [
                'staff_name' => $user->name,
                'reply' => $message->body,
            ]);
        }

        return $message;
    }

    /**
     * A reply an escalation rule posts in the business's name. It's emailed
     * like a staff reply but leaves the status and response due time alone.
     */
    public function automatedReply(Ticket $ticket, string $body): TicketMessage
    {
        $name = (string) config('app.name');
        $message = $this->addMessage($ticket, ['author_name' => $name], $body, []);

        $this->mailer->sendForTicket($ticket, 'ticket_reply', ['staff_name' => $name, 'reply' => $message->body]);

        return $message;
    }

    /**
     * Closes a ticket. Staff closing it, or closing it for inactivity, asks
     * the client for feedback when the department wants it; a client
     * closing their own ticket sees the feedback form in the portal instead.
     */
    public function close(Ticket $ticket, ?User $by = null, bool $inactive = false): void
    {
        if ($ticket->status === TicketStatus::Closed) {
            return;
        }

        $ticket->update(['status' => TicketStatus::Closed, 'escalations' => null]);
        Activity::record(($inactive ? 'Closed inactive' : 'Closed')." ticket #{$ticket->id}", $ticket, $inactive ? ['hours' => (int) config('tickets.close_inactive_hours')] : []);

        if ($inactive) {
            $this->mailer->sendForTicket($ticket, 'ticket_auto_closed', ['inactive_hours' => (int) config('tickets.close_inactive_hours')]);
        }

        if ($by !== null || $inactive) {
            $this->requestFeedback($ticket);
        }
    }

    /** Emails the client a link to rate the support, when the department asks for it and they haven't yet. */
    public function requestFeedback(Ticket $ticket): void
    {
        if ($ticket->refresh()->wantsFeedback()) {
            $this->mailer->sendForTicket($ticket, 'ticket_feedback', ['feedback_url' => $ticket->viewUrl().'#feedback']);
        }
    }

    /** @param  list<array{name: string, mime: ?string, contents: string}>  $files */
    private function addMessage(Ticket $ticket, array $attributes, string $body, array $files): TicketMessage
    {
        $message = $ticket->messages()->create($attributes + ['body' => Str::limit(trim($body), self::MAX_BODY)]);

        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            $path = "tickets/{$ticket->id}/".Str::random(40);
            Storage::disk('local')->put($path, $file['contents']);

            $message->attachments()->create([
                'path' => $path,
                'name' => Str::limit(basename(str_replace('\\', '/', $file['name'])), 200, ''),
                'mime' => $file['mime'],
                'size' => strlen($file['contents']),
            ]);
        }

        return $message;
    }

    /** Emails the assignee, or everyone who handles tickets when nobody is assigned. */
    public function alertStaff(Ticket $ticket, string $subject, string ...$lines): void
    {
        $assignee = $ticket->assignee;

        $staff = $assignee && $assignee->allows('manage-tickets')
            ? [$assignee]
            : User::all()->filter(fn (User $user) => $user->allows('manage-tickets'));

        StaffAlert::sendTo($staff, $subject, array_values($lines), 'View ticket', route('admin.tickets.show', $ticket));
    }
}
