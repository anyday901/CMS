<?php

namespace App\Import\Whmcs;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Ticket departments, tickets, replies and, optionally, their attachments. */
class TicketImporter
{
    private const DEPARTMENTS = 'Ticket departments';

    private const TICKETS = 'Tickets';

    private const REPLIES = 'Ticket replies';

    private const ATTACHMENTS = 'Ticket attachments';

    /** @var array<int, int> WHMCS department id => local department id */
    private array $departments = [];

    /** @var list<string> attachment files copied in, removed again if the import fails */
    private array $copied = [];

    /** @param  array<int, string>  $clients  imported client ids => currency */
    public function __construct(private ImportRun $run, private array $clients, private ?string $attachmentsPath = null) {}

    public function import(): void
    {
        $this->importDepartments();

        $this->run->chunk('tbltickets', function ($rows) {
            $replies = $this->run->table('tblticketreplies')->whereIn('tid', $rows->pluck('id'))->orderBy('id')->get()->groupBy('tid');

            foreach ($rows as $row) {
                $this->importTicket($row, $replies->get($row->id, collect()));
            }
        });
    }

    public function removeCopiedFiles(): void
    {
        foreach ($this->copied as $path) {
            Storage::disk('local')->delete($path);
        }
    }

    private function importDepartments(): void
    {
        $existing = DB::table('departments')->whereNotNull('email')->pluck('id', 'email')
            ->mapWithKeys(fn ($id, $email) => [strtolower($email) => $id])->all();

        foreach ($this->run->table('tblticketdepartments')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::DEPARTMENTS);
            $email = strtolower(trim((string) ($row->email ?? ''))) ?: null;

            if ($email !== null && isset($existing[$email])) {
                $this->departments[(int) $row->id] = $existing[$email];
                $this->run->report->note(self::DEPARTMENTS, "\"{$row->name}\" uses {$email}, like a department already here, so its tickets went to that one.");

                continue;
            }

            $id = DB::table('departments')->insertGetId([
                'name' => Str::limit(Whmcs::text($row->name), 250, ''),
                'email' => $email,
                'active' => ! Whmcs::on($row->hidden ?? ''),
                'created_at' => $this->run->now,
                'updated_at' => $this->run->now,
            ]);

            if ($email !== null) {
                $existing[$email] = $id;
            }

            $this->departments[(int) $row->id] = $id;
            $this->run->report->imported(self::DEPARTMENTS);
        }
    }

    /** @param  Collection<int, object>  $messages */
    private function importTicket(object $row, Collection $messages): void
    {
        $this->run->report->found(self::TICKETS);
        $this->run->report->found(self::REPLIES, $messages->count());

        $problem = match (true) {
            (int) $row->userid === 0 => 'was sent by someone without a client account',
            ! isset($this->clients[(int) $row->userid]) => "belongs to client {$row->userid}, which wasn't imported",
            ! isset($this->departments[(int) $row->did]) => "is in department {$row->did}, which wasn't found",
            (int) ($row->merged_ticket_id ?? 0) > 0 => "was merged into ticket {$row->merged_ticket_id}",
            default => null,
        };

        if ($problem !== null) {
            $this->run->report->skipped(self::TICKETS, "Ticket #{$row->tid} ({$row->title}) {$problem}.");

            return;
        }

        $opened = Whmcs::dateTime($row->date) ?? $this->run->now;

        DB::table('tickets')->insert([
            'id' => (int) $row->id,
            'whmcs_tid' => Str::limit((string) $row->tid, 32, ''),
            'client_id' => (int) $row->userid,
            'department_id' => $this->departments[(int) $row->did],
            'subject' => Whmcs::short($row->title) ?? '(No subject)',
            'status' => Whmcs::ticketStatus($row->status)->value,
            'priority' => Whmcs::priority($row->urgency)->value,
            'last_reply_at' => Whmcs::dateTime($row->lastreply ?? null) ?? $opened,
            'created_at' => $opened,
            'updated_at' => $this->run->now,
        ]);

        $this->message((int) $row->id, (int) $row->userid, $row, $opened);

        foreach ($messages as $reply) {
            $this->message((int) $row->id, (int) $row->userid, $reply, Whmcs::dateTime($reply->date) ?? $opened);
            $this->run->report->imported(self::REPLIES);
        }

        $this->run->report->imported(self::TICKETS);
    }

    /** A ticket's first message (kept on the ticket row in WHMCS) or one of its replies. */
    private function message(int $ticketId, int $clientId, object $row, string $at): void
    {
        $staff = trim((string) ($row->admin ?? '')) !== '';
        $files = array_values(array_filter(explode('|', (string) ($row->attachment ?? ''))));
        $body = Whmcs::text($row->message ?? '');

        if ($body === '') {
            $body = $files !== [] ? '(Attachments only.)' : '(No message.)';
        }

        $messageId = DB::table('ticket_messages')->insertGetId([
            'ticket_id' => $ticketId,
            'user_id' => null,
            'client_id' => $staff ? null : $clientId,
            'author_name' => Whmcs::short($staff ? $row->admin : ($row->name ?? '')),
            'body' => $body,
            'internal' => false,
            'via' => 'whmcs',
            'created_at' => $at,
        ]);

        foreach ($files as $stored) {
            $this->attach($ticketId, $messageId, $stored, $at);
        }
    }

    private function attach(int $ticketId, int $messageId, string $stored, string $at): void
    {
        $this->run->report->found(self::ATTACHMENTS);

        if ($this->attachmentsPath === null) {
            $this->run->report->skipped(self::ATTACHMENTS, "{$stored} on ticket {$ticketId}: run with --attachments to bring files over.");

            return;
        }

        $file = rtrim($this->attachmentsPath, '/').'/'.basename($stored);

        if (! is_file($file)) {
            $this->run->report->skipped(self::ATTACHMENTS, "{$stored} on ticket {$ticketId} is missing from {$this->attachmentsPath}.");

            return;
        }

        $path = "tickets/{$ticketId}/".Str::random(40);

        if (! $this->run->report->dryRun) {
            Storage::disk('local')->put($path, (string) file_get_contents($file));
            $this->copied[] = $path;
        }

        $this->run->queue('ticket_attachments', [
            'ticket_message_id' => $messageId,
            'path' => $path,
            'name' => Str::limit(Whmcs::attachmentName($stored), 250, ''),
            'mime' => mime_content_type($file) ?: null,
            'size' => (int) filesize($file),
            'created_at' => $at,
        ]);
        $this->run->report->imported(self::ATTACHMENTS);
    }
}
