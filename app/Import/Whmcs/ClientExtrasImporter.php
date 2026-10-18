<?php

namespace App\Import\Whmcs;

use App\Models\TaxRule;
use App\Models\Todo;
use Illuminate\Support\Facades\DB;

/**
 * Client groups, staff notes on clients and the admin to-do list from WHMCS.
 * Groups come before clients, so each client can be put in theirs; notes
 * and to-dos come after staff, so they keep who wrote them or is on them.
 */
class ClientExtrasImporter
{
    private const GROUPS = 'Client groups';

    private const NOTES = 'Client notes';

    private const TODOS = 'To-do items';

    /** WHMCS to-do statuses and the ones here. */
    private const STATUSES = [
        'new' => Todo::OPEN,
        'incomplete' => Todo::OPEN,
        'in progress' => Todo::IN_PROGRESS,
        'pending' => Todo::WAITING,
        'postponed' => Todo::WAITING,
        'completed' => Todo::DONE,
    ];

    /** @var array<int, int> WHMCS group id => client group id */
    private array $groups = [];

    public function __construct(private ImportRun $run) {}

    public function importGroups(): void
    {
        if (! $this->run->hasTables('tblclientgroups')) {
            return;
        }

        foreach ($this->run->table('tblclientgroups')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::GROUPS);
            $name = Whmcs::short($row->groupname ?? '') ?? "Group {$row->id}";
            $colour = preg_match('/^#[0-9a-f]{6}$/i', trim((string) ($row->groupcolour ?? ''))) ? strtolower(trim($row->groupcolour)) : '#64748b';

            $discount = max(0, min(10000, (int) round((float) ($row->discountpercent ?? 0) * 100)));
            $this->groups[(int) $row->id] = (int) (DB::table('client_groups')->where('name', $name)->value('id') ?? DB::table('client_groups')->insertGetId([
                'name' => $name,
                'colour' => $colour,
                'discount_percent' => $discount,
                'exempt_suspension' => Whmcs::on($row->susptermexempt ?? ''),
                'separate_invoices' => Whmcs::on($row->separateinvoices ?? ''),
                'created_at' => $this->run->now,
                'updated_at' => $this->run->now,
            ]));
            $this->run->report->imported(self::GROUPS);

            if ($discount > 0) {
                // WHMCS took the discount off each service's recurring amount when it was ordered, and those amounts come over as they are.
                $this->run->report->note(self::GROUPS, "Group {$name} gets its ".TaxRule::formatRate($discount).' discount on new orders. Imported services already have it in their price, so it isn\'t taken off renewals unless you tick that on the group.');
            }
        }
    }

    /** The group a WHMCS client was in. */
    public function groupId(mixed $whmcsGroupId): ?int
    {
        return $this->groups[(int) $whmcsGroupId] ?? null;
    }

    /** @param  array<int, string>  $clients  the imported clients, by id */
    public function importNotes(array $clients, StaffImporter $staff): void
    {
        if (! $this->run->hasTables('tblnotes')) {
            return;
        }

        foreach ($this->run->table('tblnotes')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::NOTES);
            $body = Whmcs::text($row->note ?? '');

            if (! isset($clients[(int) $row->userid]) || $body === '') {
                $this->run->report->skipped(self::NOTES, "Note {$row->id} is empty or belongs to client {$row->userid}, which wasn't imported.");

                continue;
            }

            $created = Whmcs::dateTime($row->created ?? null) ?? $this->run->now;
            $this->run->queue('client_notes', [
                'client_id' => (int) $row->userid,
                'user_id' => $staff->userId($row->adminid ?? 0),
                'body' => $body,
                'pinned' => Whmcs::on($row->sticky ?? ''),
                'created_at' => $created,
                'updated_at' => Whmcs::dateTime($row->modified ?? null) ?? $created,
            ]);
            $this->run->report->imported(self::NOTES);
        }
    }

    public function importTodos(StaffImporter $staff): void
    {
        if (! $this->run->hasTables('tbltodolist')) {
            return;
        }

        foreach ($this->run->table('tbltodolist')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::TODOS);
            $status = self::STATUSES[strtolower(trim((string) ($row->status ?? '')))] ?? Todo::OPEN;
            $created = Whmcs::dateTime($row->date ?? null) ?? $this->run->now;

            $this->run->queue('todos', [
                'title' => Whmcs::short($row->title ?? '') ?? "To-do {$row->id}",
                'description' => Whmcs::text($row->description ?? '') ?: null,
                'status' => $status,
                'due_on' => Whmcs::date($row->duedate ?? null),
                'user_id' => $staff->userId($row->admin ?? 0),
                'completed_at' => $status === Todo::DONE ? $created : null,
                'created_at' => $created,
                'updated_at' => $this->run->now,
            ]);
            $this->run->report->imported(self::TODOS);
        }
    }
}
