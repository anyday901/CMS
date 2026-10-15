<?php

namespace App\Import\Whmcs;

use App\Enums\TicketStatus;
use App\Models\CustomField;
use App\Models\TicketFeedback;
use App\Models\TicketTag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ticket departments, tickets (from clients and from guests without an
 * account), replies, feedback ratings and, optionally, attachments.
 */
class TicketImporter
{
    private const DEPARTMENTS = 'Ticket departments';

    private const TICKETS = 'Tickets';

    private const REPLIES = 'Ticket replies';

    private const ATTACHMENTS = 'Ticket attachments';

    private const FIELDS = 'Ticket custom fields';

    private const FEEDBACK = 'Ticket feedback';

    /** @var array<int, int> WHMCS department id => local department id */
    private array $departments = [];

    /** @var list<string> attachment files copied in, removed again if the import fails */
    private array $copied = [];

    /** @var array<int, array{id: int, type: string}> WHMCS ticket custom field id => local field */
    private array $fields = [];

    /** @var array<string, int> tag name => local tag id */
    private array $tags = [];

    /** @var array<int, true> imported ticket ids */
    private array $imported = [];

    /** Tickets from people without a client account. */
    private int $guests = 0;

    /** @var array<int, object> merged WHMCS tickets, by id, brought over once every ticket is in */
    private array $merged = [];

    /** @param  array<int, string>  $clients  imported client ids => currency */
    public function __construct(private ImportRun $run, private array $clients, private StaffImporter $staff, private ?string $attachmentsPath = null) {}

    public function import(): void
    {
        $this->importDepartments();
        $this->importFields();

        $this->run->chunk('tbltickets', function ($rows) {
            $ids = $rows->pluck('id');
            $replies = $this->run->table('tblticketreplies')->whereIn('tid', $ids)->orderBy('id')->get()->groupBy('tid');
            $values = $this->fields === [] ? collect() : $this->run->table('tblcustomfieldsvalues')
                ->whereIn('fieldid', array_keys($this->fields))->whereIn('relid', $ids)->get()->groupBy('relid');
            $tags = $this->run->hasTables('tbltickettags')
                ? $this->run->table('tbltickettags')->whereIn('ticketid', $ids)->orderBy('id')->get()->groupBy('ticketid')
                : collect();

            foreach ($rows as $row) {
                $this->importTicket($row, $replies->get($row->id, collect()), $values->get($row->id, collect()), $tags->get($row->id, collect()));
            }
        });

        $this->importMerged();
        $this->importFeedback();

        if ($this->guests > 0) {
            $this->run->report->note(self::TICKETS, "{$this->guests} ticket(s) came from people without a client account and are guest tickets here. Their replies by email still reach them; turn on guest tickets under Settings to accept new tickets from people without an account too.");
        }
    }

    /** @return array<int, int> WHMCS department id => local department id */
    public function departments(): array
    {
        return $this->departments;
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

    /** Custom fields of support departments, which WHMCS keeps with the client and product fields. */
    private function importFields(): void
    {
        if (! $this->run->hasTables('tblcustomfields', 'tblcustomfieldsvalues')) {
            return;
        }

        foreach ($this->run->table('tblcustomfields')->where('type', 'support')->orderBy('sortorder')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::FIELDS);
            $department = (int) $row->relid > 0 ? ($this->departments[(int) $row->relid] ?? null) : null;

            if ((int) $row->relid > 0 && $department === null) {
                $this->run->report->skipped(self::FIELDS, '"'.Whmcs::label($row->fieldname)."\" belongs to department {$row->relid}, which wasn't found.");

                continue;
            }

            $type = CatalogImporter::fieldType($row->fieldtype);
            $id = DB::table('custom_fields')->insertGetId([
                'entity' => CustomField::TICKET,
                'department_id' => $department,
                'name' => Str::limit(Whmcs::label($row->fieldname), 250, ''),
                'type' => $type,
                'options' => $type === 'dropdown' ? CatalogImporter::dropdownOptions($row->fieldoptions) : null,
                'help' => Whmcs::short($row->description ?? ''),
                'required' => Whmcs::on($row->required ?? ''),
                'admin_only' => Whmcs::on($row->adminonly ?? ''),
                'sort_order' => (int) ($row->sortorder ?? 0),
                'created_at' => $this->run->now,
                'updated_at' => $this->run->now,
            ]);

            $this->fields[(int) $row->id] = ['id' => $id, 'type' => $type];
            $this->run->report->imported(self::FIELDS);
        }
    }

    /**
     * @param  Collection<int, object>  $messages
     * @param  Collection<int, object>  $values  custom field values
     * @param  Collection<int, object>  $tags
     */
    private function importTicket(object $row, Collection $messages, Collection $values, Collection $tags): void
    {
        if ((int) ($row->merged_ticket_id ?? 0) > 0) {
            $this->merged[(int) $row->id] = $row;

            return;
        }

        $this->run->report->found(self::TICKETS);
        $this->run->report->found(self::REPLIES, $messages->count());

        $guestEmail = strtolower(trim((string) ($row->email ?? '')));
        $guest = (int) $row->userid === 0;

        $problem = match (true) {
            $guest && ! filter_var($guestEmail, FILTER_VALIDATE_EMAIL) => 'was sent by someone without a client account or a valid email address',
            ! $guest && ! isset($this->clients[(int) $row->userid]) => "belongs to client {$row->userid}, which wasn't imported",
            ! isset($this->departments[(int) $row->did]) => "is in department {$row->did}, which wasn't found",
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
            'client_id' => $guest ? null : (int) $row->userid,
            'guest_name' => $guest ? (Whmcs::short($row->name ?? '') ?? Str::before($guestEmail, '@')) : null,
            'guest_email' => $guest ? Str::limit($guestEmail, 250, '') : null,
            'access_key' => $guest ? Str::random(40) : null,
            'department_id' => $this->departments[(int) $row->did],
            'assigned_to' => $this->staff->userId($row->flag ?? 0),
            'subject' => Whmcs::short($row->title) ?? '(No subject)',
            'status' => Whmcs::ticketStatus($row->status)->value,
            'priority' => Whmcs::priority($row->urgency)->value,
            'custom_fields' => $this->fieldValues($values),
            'last_reply_at' => Whmcs::dateTime($row->lastreply ?? null) ?? $opened,
            'closed_at' => Whmcs::ticketStatus($row->status) === TicketStatus::Closed ? (Whmcs::dateTime($row->lastreply ?? null) ?? $opened) : null,
            // For response time reports: when an admin first answered.
            'first_response_at' => $messages->filter(fn ($reply) => filled($reply->admin ?? null))->map(fn ($reply) => Whmcs::dateTime($reply->date))->filter()->min(),
            'created_at' => $opened,
            'updated_at' => $this->run->now,
        ]);
        $this->imported[(int) $row->id] = true;
        $this->tag((int) $row->id, $tags);

        $this->message((int) $row->id, $guest ? null : (int) $row->userid, $row, $opened);

        foreach ($messages as $reply) {
            $this->message((int) $row->id, $guest ? null : (int) $row->userid, $reply, Whmcs::dateTime($reply->date) ?? $opened);
            $this->run->report->imported(self::REPLIES);
        }
        $this->guests += $guest ? 1 : 0;

        $this->run->report->imported(self::TICKETS);
    }

    /**
     * Tickets merged into another in WHMCS. Their messages are already on
     * the ticket they were merged into; each comes over closed and pointing
     * at it, so its old number still leads somewhere.
     */
    private function importMerged(): void
    {
        foreach ($this->merged as $id => $row) {
            $this->run->report->found(self::TICKETS);
            $into = (int) $row->merged_ticket_id;
            $seen = [$id => true];

            // Follow a ticket merged into one that was itself merged later.
            while (isset($this->merged[$into]) && ! isset($seen[$into])) {
                $seen[$into] = true;
                $into = (int) $this->merged[$into]->merged_ticket_id;
            }

            if (! isset($this->imported[$into])) {
                $this->run->report->skipped(self::TICKETS, "Ticket #{$row->tid} ({$row->title}) was merged into ticket {$row->merged_ticket_id}, which wasn't imported.");

                continue;
            }

            $opened = Whmcs::dateTime($row->date) ?? $this->run->now;
            $target = DB::table('tickets')->where('id', $into)->first(['client_id', 'guest_name', 'guest_email', 'department_id']);
            DB::table('tickets')->insert([
                'id' => $id,
                'whmcs_tid' => Str::limit((string) $row->tid, 32, ''),
                'client_id' => $target->client_id,
                'guest_name' => $target->guest_name,
                'guest_email' => $target->guest_email,
                'access_key' => $target->guest_email !== null ? Str::random(40) : null,
                'merged_into_id' => $into,
                'department_id' => (int) $target->department_id,
                'subject' => Whmcs::short($row->title) ?? '(No subject)',
                'status' => TicketStatus::Closed->value,
                'priority' => Whmcs::priority($row->urgency)->value,
                'last_reply_at' => Whmcs::dateTime($row->lastreply ?? null) ?? $opened,
                'closed_at' => Whmcs::dateTime($row->lastreply ?? null) ?? $opened,
                'created_at' => $opened,
                'updated_at' => $this->run->now,
            ]);
            $this->run->report->imported(self::TICKETS);
        }

        if ($this->merged !== []) {
            $this->run->report->note(self::TICKETS, 'Tickets merged in WHMCS came over closed, pointing at the ticket they were merged into, so their numbers and email replies still find it.');
        }
    }

    /**
     * Ratings clients gave after a ticket closed. WHMCS asks them to rate
     * each admin who replied, out of 10; here a ticket has one rating out
     * of 5, so a ticket's ratings are averaged and halved, rounding up.
     */
    private function importFeedback(): void
    {
        if (! $this->run->hasTables('tblticketfeedback')) {
            return;
        }

        $rows = $this->run->table('tblticketfeedback')->orderBy('id')->get()->groupBy(fn ($row) => (int) $row->ticketid);
        $split = 0;

        foreach ($rows as $ticketId => $ratings) {
            $this->run->report->found(self::FEEDBACK);

            if (! isset($this->imported[$ticketId])) {
                $this->run->report->skipped(self::FEEDBACK, "Feedback on ticket {$ticketId}, which wasn't imported.");

                continue;
            }

            $scores = $ratings->map(fn ($row) => max(1, min(10, (int) $row->rating)));
            $comments = $ratings->map(fn ($row) => Whmcs::plain((string) ($row->comments ?? '')))->filter()->unique()->values();
            $at = $ratings->map(fn ($row) => Whmcs::dateTime($row->datetime ?? null))->filter()->max() ?? $this->run->now;
            $split += $ratings->count() > 1 ? 1 : 0;

            DB::table('ticket_feedback')->insert([
                'ticket_id' => $ticketId,
                'client_id' => DB::table('tickets')->where('id', $ticketId)->value('client_id'),
                'rating' => (int) min(TicketFeedback::MAX_RATING, max(1, ceil($scores->avg() / 2))),
                'comment' => $comments->isEmpty() ? null : Str::limit($comments->join("\n\n"), 2000, ''),
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            $this->run->report->imported(self::FEEDBACK);
        }

        if ($rows->isNotEmpty()) {
            $this->run->report->note(self::FEEDBACK, 'WHMCS ratings are out of 10 and here they\'re out of 5, so each was halved and rounded up.'
                .($split > 0 ? " {$split} ticket(s) had a rating for each admin who replied; here they have one, the average of those." : ''));
        }
    }

    /** @param  Collection<int, object>  $values */
    private function fieldValues(Collection $values): ?string
    {
        $saved = [];

        foreach ($values as $value) {
            $field = $this->fields[(int) $value->fieldid] ?? null;
            if ($field === null) {
                continue;
            }

            $checked = Whmcs::on($value->value) ? '1' : '';
            $text = $field['type'] === CustomField::CHECKBOX ? $checked : Whmcs::text($value->value);

            if ($text !== '') {
                $saved[$field['id']] = $text;
            }
        }

        return Whmcs::fieldValues($saved);
    }

    /** @param  Collection<int, object>  $tags */
    private function tag(int $ticketId, Collection $tags): void
    {
        foreach (TicketTag::normalize($tags->map(fn ($tag) => Whmcs::text($tag->tag))) as $name) {
            $this->tags[$name] ??= DB::table('ticket_tags')->insertGetId(['name' => $name, 'created_at' => $this->run->now, 'updated_at' => $this->run->now]);
            $this->run->queue('ticket_ticket_tag', ['ticket_id' => $ticketId, 'ticket_tag_id' => $this->tags[$name]]);
        }
    }

    /**
     * A ticket's first message (kept on the ticket row in WHMCS) or one of
     * its replies. $clientId is null on a guest's ticket. A staff reply is
     * linked to the staff account of the admin who wrote it, by name.
     */
    private function message(int $ticketId, ?int $clientId, object $row, string $at): void
    {
        $staff = trim((string) ($row->admin ?? '')) !== '';
        $files = array_values(array_filter(explode('|', (string) ($row->attachment ?? ''))));
        $body = Whmcs::text($row->message ?? '');

        if ($body === '') {
            $body = $files !== [] ? '(Attachments only.)' : '(No message.)';
        }

        $messageId = DB::table('ticket_messages')->insertGetId([
            'ticket_id' => $ticketId,
            'user_id' => $staff ? $this->staff->userIdByName(Whmcs::text($row->admin)) : null,
            'client_id' => $staff ? null : $clientId,
            'guest' => ! $staff && $clientId === null,
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
