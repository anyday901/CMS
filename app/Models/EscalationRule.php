<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Changes tickets that match its conditions, such as moving urgent tickets
 * nobody has answered for an hour to another department and emailing a
 * manager. Each rule runs once on a ticket until someone replies to it.
 */
class EscalationRule extends Model
{
    protected $fillable = [
        'name', 'active', 'sort_order', 'department_ids', 'statuses', 'priorities', 'minutes_since_reply', 'sla_breached',
        'set_department_id', 'set_status', 'set_priority', 'assign_to', 'notify_assignee', 'notify_user_ids', 'reply',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
            'department_ids' => 'array',
            'statuses' => 'array',
            'priorities' => 'array',
            'minutes_since_reply' => 'integer',
            'sla_breached' => 'boolean',
            'notify_assignee' => 'boolean',
            'notify_user_ids' => 'array',
            'set_status' => TicketStatus::class,
            'set_priority' => TicketPriority::class,
        ];
    }

    public function setDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'set_department_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assign_to');
    }

    /** @param  Builder<self>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Open tickets that meet the conditions at $now. Closed tickets never match.
     *
     * @return Builder<Ticket>
     */
    public function tickets(DateTimeInterface $now): Builder
    {
        return Ticket::query()
            ->where('status', '!=', TicketStatus::Closed->value)
            ->when($this->department_ids, fn ($query, $ids) => $query->whereIn('department_id', $ids))
            ->when($this->statuses, fn ($query, $statuses) => $query->whereIn('status', $statuses))
            ->when($this->priorities, fn ($query, $priorities) => $query->whereIn('priority', $priorities))
            ->when($this->minutes_since_reply, fn ($query, $minutes) => $query->where('last_reply_at', '<=', CarbonImmutable::instance($now)->subMinutes($minutes)))
            ->when($this->sla_breached, fn ($query) => $query->whereNotNull('response_due_at')->where('response_due_at', '<', $now));
    }

    /** @return list<string> The conditions in words, for the rules list. */
    public function conditionSummary(): array
    {
        $departments = $this->department_ids ? Department::whereIn('id', $this->department_ids)->orderBy('name')->pluck('name')->join(', ') : null;

        return array_values(array_filter([
            $departments ? "in {$departments}" : null,
            $this->statuses ? 'status '.collect($this->statuses)->map(fn ($status) => TicketStatus::from($status)->label())->join(' or ') : null,
            $this->priorities ? 'priority '.collect($this->priorities)->map(fn ($priority) => TicketPriority::from($priority)->label())->join(' or ') : null,
            $this->minutes_since_reply ? "no reply for {$this->minutes_since_reply} minutes" : null,
            $this->sla_breached ? 'response overdue' : null,
        ]));
    }

    /** @return list<string> The actions in words. */
    public function actionSummary(): array
    {
        return array_values(array_filter([
            $this->setDepartment ? "move to {$this->setDepartment->name}" : null,
            $this->set_status ? "set status to {$this->set_status->label()}" : null,
            $this->set_priority ? "set priority to {$this->set_priority->label()}" : null,
            $this->assignee ? "assign to {$this->assignee->name}" : null,
            $this->notify_assignee || $this->notify_user_ids ? 'email staff' : null,
            filled($this->reply) ? 'reply to the client' : null,
        ]));
    }
}
