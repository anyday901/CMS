<?php

namespace App\Tickets;

use App\Mail\EmailTemplates;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Department;
use App\Models\InboundEmail;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\Message;
use ZBateson\MailMimeParser\Message\IMessagePart;

/**
 * Turns a piped email into a ticket reply or a new ticket.
 *
 * A message whose subject carries "[Ticket #123]" from the address of that
 * ticket's client (or one of their contacts who gets support emails), or
 * from the guest who opened it, is a reply. Otherwise a message from a known
 * client or such a contact to a department's address opens a new ticket, and
 * so does one from anyone else when guest tickets are on under Settings.
 * The spam filter runs first, and every email is logged with what became
 * of it.
 */
class InboundMail
{
    public const REPLIED = 'replied';

    public const OPENED = 'opened';

    public const IGNORED = 'ignored';

    public const SPAM = InboundEmail::SPAM;

    private const TICKET_PATTERN = '/\[Ticket #(\d+)\]/i';

    /** Subjects of emails sent by WHMCS before the switch, such as "[Ticket ID: 482913]". */
    private const WHMCS_TICKET_PATTERN = '/\[Ticket ID: ([A-Z0-9-]+)\]/i';

    public function __construct(private TicketDesk $desk, private SpamFilter $spam) {}

    /** @return array{0: string, 1: string} The outcome and a short reason, for logs and command output. */
    public function handle(string $raw, ?Department $department = null): array
    {
        $message = Message::from($raw, false);
        $from = strtolower((string) $message->getHeader('From')?->getEmail());
        $subject = trim((string) $message->getSubject());
        $named = $this->ticketIn($subject);
        $client = $from === '' ? null : $this->clientFor($from, $named);
        // The ticket this answers: one of the client's, or the guest's own.
        $replyTo = match (true) {
            $named === null => null,
            $client !== null => $named->client_id === $client->id ? $named : null,
            default => $named->isGuest() && strcasecmp((string) $named->guest_email, $from) === 0 ? $named : null,
        };
        $body = $this->body($message);
        $files = $this->attachments($message);
        $messageId = Str::limit(trim((string) $message->getMessageId()), 250, '') ?: null;
        $log = ['from' => Str::limit($from, 250, '') ?: null, 'subject' => Str::limit($subject, 250, '') ?: null];

        $rejected = match (true) {
            $from === '' => 'no sender address',
            $this->isAutomatic($message) || self::isMailSystem($from) => "automatic message from {$from}",
            $this->isOwnAddress($from) => "email from our own address {$from}, which would loop",
            $client === null && $replyTo === null && ! config('tickets.guest_tickets') => "sender {$from} is not a client",
            $body === '' && $files === [] => "empty message from {$from}",
            $messageId !== null && TicketMessage::where('email_message_id', $messageId)->exists() => "already received {$messageId}",
            default => null,
        };

        if ($rejected !== null) {
            return $this->finish(self::IGNORED, $rejected, $log);
        }

        $spam = $this->spam->check($from, $subject, $body, $message);
        if ($spam !== null) {
            return $this->finish(self::SPAM, "dropped the email from {$from}: {$spam}", $log);
        }

        $sender = new Sender($from, trim((string) $message->getHeader('From')?->getPersonName()), $client, $replyTo);

        try {
            return $this->apply($sender, $message, $body, $files, $department, new EmailOrigin($messageId), $log);
        } catch (UniqueConstraintViolationException) {
            // The same email arrived twice at once and the other delivery won.
            return $this->finish(self::IGNORED, "already received {$messageId}", $log);
        }
    }

    /**
     * @param  list<array{name: string, mime: ?string, contents: string}>  $files
     * @param  array{from: ?string, subject: ?string}  $log
     * @return array{0: string, 1: string}
     */
    private function apply(Sender $sender, IMessage $message, string $body, array $files, ?Department $department, EmailOrigin $origin, array $log): array
    {
        $subject = trim((string) $message->getSubject());
        $body = $body !== '' ? $body : '(Attachments only.)';

        if ($sender->replyTo !== null) {
            $this->desk->clientReply($sender->replyTo, $body, $files, $origin);

            return $this->finish(self::REPLIED, "reply on ticket #{$sender->replyTo->id} from {$sender->email}", $log, $sender->replyTo);
        }

        $department ??= $this->departmentFor($message);

        if ($department === null) {
            return $this->finish(self::IGNORED, "no department uses any address the email from {$sender->email} was sent to", $log);
        }

        $limit = (int) config('tickets.spam.new_tickets_per_hour');
        if ($limit > 0 && $this->openedLastHour($sender) >= $limit) {
            return $this->finish(self::SPAM, "{$sender->email} already opened {$limit} tickets in the last hour", $log);
        }

        $subject = $subject !== '' ? $subject : '(No subject)';
        $ticket = $sender->client !== null
            ? $this->desk->open($sender->client, $department, $subject, $body, files: $files, email: $origin)
            : $this->desk->openForGuest($sender->name, $sender->email, $department, $subject, $body, files: $files, origin: $origin);

        return $this->finish(self::OPENED, "ticket #{$ticket->id} from ".($sender->client !== null ? $sender->email : "guest {$sender->email}"), $log, $ticket);
    }

    /** Tickets the sender opened in the last hour, by email or otherwise. */
    private function openedLastHour(Sender $sender): int
    {
        return Ticket::where('created_at', '>=', now()->subHour())
            ->when($sender->client,
                fn ($query, Client $client) => $query->where('client_id', $client->id),
                fn ($query) => $query->whereNull('client_id')->where('guest_email', $sender->email))
            ->count();
    }

    /**
     * The client with this address, or the client of a contact who gets
     * support emails. One address can be a contact for several clients: a
     * reply to a ticket goes to that ticket's client, and a new email from an
     * address shared by several clients is ignored rather than guessed.
     */
    private function clientFor(string $from, ?Ticket $named): ?Client
    {
        $client = Client::whereRaw('lower(email) = ?', [$from])->first();
        if ($client !== null) {
            return $client;
        }

        $clientIds = ClientContact::where('support_emails', true)->whereRaw('lower(email) = ?', [$from])->distinct()->pluck('client_id');
        $ticketClient = $named?->client_id;

        return match (true) {
            $ticketClient !== null && $clientIds->contains($ticketClient) => Client::find($ticketClient),
            $clientIds->count() === 1 => Client::find($clientIds->first()),
            default => null,
        };
    }

    /** The ticket a subject line names, by its number here or its old WHMCS number, or the ticket it was merged into. */
    private function ticketIn(string $subject): ?Ticket
    {
        $ticket = match (true) {
            (bool) preg_match(self::TICKET_PATTERN, $subject, $m) => Ticket::find((int) $m[1]),
            (bool) preg_match(self::WHMCS_TICKET_PATTERN, $subject, $m) => Ticket::where('whmcs_tid', $m[1])->first(),
            default => null,
        };

        return $ticket?->merged_into_id !== null ? $ticket->mergedInto : $ticket;
    }

    /**
     * Logs what became of the email.
     *
     * @param  array{from: ?string, subject: ?string}  $log
     * @return array{0: string, 1: string}
     */
    private function finish(string $outcome, string $reason, array $log, ?Ticket $ticket = null): array
    {
        if (in_array($outcome, [self::IGNORED, self::SPAM], true)) {
            Log::info("Inbound ticket email {$outcome}: {$reason}.");
        }

        InboundEmail::create($log + ['outcome' => $outcome, 'reason' => Str::limit($reason, 250, ''), 'ticket_id' => $ticket?->id]);

        return [$outcome, $reason];
    }

    /** Auto-replies and bulk mail, which would otherwise loop or flood the queue. */
    private function isAutomatic(IMessage $message): bool
    {
        $auto = strtolower((string) $message->getHeaderValue('Auto-Submitted'));
        $precedence = strtolower((string) $message->getHeaderValue('Precedence'));

        return ($auto !== '' && $auto !== 'no')
            || in_array($precedence, ['bulk', 'junk', 'list', 'auto_reply'], true)
            || $message->getHeaderValue('X-Autoreply') !== null
            || $message->getHeaderValue('X-Autorespond') !== null;
    }

    /** Bounces and delivery reports, which come from the mail system rather than a person. */
    private static function isMailSystem(string $from): bool
    {
        return in_array(strtolower(Str::before($from, '@')), ['mailer-daemon', 'postmaster'], true);
    }

    /** The address ticket emails go out from, or a department's: a reply from it would open tickets in a loop. */
    private function isOwnAddress(string $from): bool
    {
        return strcasecmp($from, (string) config('mail.from.address')) === 0
            || Department::whereNotNull('email')->whereRaw('lower(email) = ?', [$from])->exists();
    }

    private function departmentFor(IMessage $message): ?Department
    {
        $addresses = [];

        foreach (['To', 'Cc', 'Delivered-To', 'X-Original-To', 'Envelope-To'] as $name) {
            foreach (array_keys($message->getAllHeadersByName($name)) as $i) {
                $header = $message->getHeaderAs($name, AddressHeader::class, $i);

                foreach ($header?->getAddresses() ?? [] as $address) {
                    $addresses[] = strtolower($address->getEmail());
                }
            }
        }

        if ($addresses === []) {
            return null;
        }

        return Department::active()
            ->whereNotNull('email')
            ->get()
            ->first(fn (Department $department) => in_array(strtolower($department->email), $addresses, true));
    }

    /** The new text of the message, without the quoted email it replies to. */
    private function body(IMessage $message): string
    {
        $text = $message->getTextContent();

        if ($text === null && ($html = $message->getHtmlContent()) !== null) {
            $html = preg_replace(['#<(br|/p|/div|/li|/tr)\b[^>]*>#i', '#<(style|script)\b.*?</\1>#is'], ["\n", ''], $html);
            $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);

        // Cut at our marker, or at the "On <date>, <name> wrote:" line most mail apps add.
        $cut = [stripos($text, EmailTemplates::REPLY_MARKER)];
        if (preg_match('/^(On\s.+wrote:|-{2,}\s*Original Message\s*-{2,}|From:\s.+)$/mi', $text, $m, PREG_OFFSET_CAPTURE)) {
            $cut[] = $m[0][1];
        }
        $cut = array_filter($cut, fn ($position) => $position !== false);

        if ($cut !== []) {
            $text = substr($text, 0, min($cut));
        }

        // Drop any quoted lines left at the end.
        $lines = explode("\n", rtrim($text));
        while ($lines !== [] && preg_match('/^\s*(>.*)?$/', end($lines))) {
            array_pop($lines);
        }

        return trim(Str::limit(implode("\n", $lines), TicketDesk::MAX_BODY));
    }

    /** @return list<array{name: string, mime: ?string, contents: string}> */
    private function attachments(IMessage $message): array
    {
        $files = [];

        /** @var IMessagePart $part */
        foreach ($message->getAllAttachmentParts() as $part) {
            $name = (string) $part->getFilename();
            $contents = $part->getBinaryContentStream()?->getContents() ?? '';
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if ($name === '' || $contents === '' || ! in_array($extension, TicketDesk::EXTENSIONS, true)
                || strlen($contents) > TicketDesk::MAX_KB * 1024 || count($files) >= TicketDesk::MAX_FILES) {
                continue;
            }

            $files[] = ['name' => $name, 'mime' => $part->getContentType(), 'contents' => $contents];
        }

        return $files;
    }
}
