<?php

namespace App\Tickets;

use App\Models\Activity;
use App\Models\EscalationRule;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\StaffAlert;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Runs the escalation rules, in order, over the open tickets. A rule changes
 * a ticket once; it can run on it again after the next client or staff reply.
 */
class TicketEscalator
{
    public function __construct(private TicketDesk $desk) {}

    /** @return int How many times a rule was applied to a ticket. */
    public function run(DateTimeInterface $now): int
    {
        $applied = 0;

        foreach (EscalationRule::where('active', true)->ordered()->get() as $rule) {
            foreach ($rule->tickets($now)->with(['client', 'department', 'assignee'])->orderBy('id')->lazyById(200) as $ticket) {
                if (! in_array($rule->id, $ticket->escalations ?? [], true)) {
                    $this->apply($rule, $ticket);
                    $applied++;
                }
            }
        }

        return $applied;
    }

    public function apply(EscalationRule $rule, Ticket $ticket): void
    {
        DB::transaction(function () use ($rule, $ticket) {
            $ticket->fill(array_filter([
                'department_id' => $rule->set_department_id,
                'status' => $rule->set_status,
                'priority' => $rule->set_priority,
                'assigned_to' => $rule->assign_to,
            ], fn ($value) => $value !== null));
            $ticket->escalations = [...($ticket->escalations ?? []), $rule->id];
            $ticket->save();

            Activity::record("Escalation rule \"{$rule->name}\" applied to ticket #{$ticket->id}", $ticket);
        });

        if (filled($rule->reply)) {
            $this->desk->automatedReply($ticket, $rule->reply);
        }

        $this->notify($rule, $ticket->refresh());
    }

    private function notify(EscalationRule $rule, Ticket $ticket): void
    {
        $staff = User::whereIn('id', $rule->notify_user_ids ?? [])->get();

        if ($rule->notify_assignee && $ticket->assignee) {
            $staff->push($ticket->assignee);
        }

        StaffAlert::sendTo(
            $staff->unique('id')->filter(fn (User $user) => $user->allows('manage-tickets')),
            "Escalated ticket #{$ticket->id}: {$ticket->subject}",
            ["The escalation rule \"{$rule->name}\" matched this ticket in {$ticket->department->name} ({$ticket->priority->label()} priority, status {$ticket->status->label()})."],
            'View ticket',
            route('admin.tickets.show', $ticket),
        );
    }
}
