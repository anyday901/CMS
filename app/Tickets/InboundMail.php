<?php

namespace App\Tickets;

use App\Mail\EmailTemplates;
use App\Models\Client;
use App\Models\Department;
use App\Models\Ticket;
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
 * ticket's client is a reply. Otherwise a message from a known client to a
 * department's address opens a new ticket. Anything else is ignored, so
 * strangers and spam can't open tickets.
 */
class InboundMail
{
    public const REPLIED = 'replied';

    public const OPENED = 'opened';

    public const IGNORED = 'ignored';

    public function __construct(private TicketDesk $desk) {}

    /** @return array{0: string, 1: string} The outcome and a short reason, for logs and command output. */
    public function handle(string $raw, ?Department $department = null): array
    {
        $message = Message::from($raw, false);
        $from = strtolower((string) $message->getHeader('From')?->getEmail());

        if ($from === '') {
            return $this->ignore('no sender address');
        }

        if ($this->isAutomatic($message)) {
            return $this->ignore("automatic message from {$from}");
        }

        $client = Client::whereRaw('lower(email) = ?', [$from])->first();

        if ($client === null) {
            return $this->ignore("sender {$from} is not a client");
        }

        $subject = trim((string) $message->getSubject());
        $body = $this->body($message);
        $files = $this->attachments($message);

        if ($body === '' && $files === []) {
            return $this->ignore("empty message from {$from}");
        }

        if (preg_match('/\[Ticket #(\d+)\]/i', $subject, $m)) {
            $ticket = Ticket::where('client_id', $client->id)->find((int) $m[1]);

            if ($ticket !== null) {
                $this->desk->clientReply($ticket, $body !== '' ? $body : '(Attachments only.)', $files, 'email');

                return [self::REPLIED, "reply on ticket #{$ticket->id} from {$from}"];
            }
        }

        $department ??= $this->departmentFor($message);

        if ($department === null) {
            return $this->ignore("no department uses any address the email from {$from} was sent to");
        }

        $ticket = $this->desk->open(
            $client,
            $department,
            $subject !== '' ? $subject : '(No subject)',
            $body !== '' ? $body : '(Attachments only.)',
            files: $files,
            via: 'email',
        );

        return [self::OPENED, "ticket #{$ticket->id} from {$from}"];
    }

    /** @return array{0: string, 1: string} */
    private function ignore(string $reason): array
    {
        Log::info("Inbound ticket email ignored: {$reason}.");

        return [self::IGNORED, $reason];
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
